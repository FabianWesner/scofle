<?php

namespace App\Services;

use App\AttemptStatus;
use App\FailureCode;
use App\Models\Attempt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class AttemptConverter
{
    public function __construct(private readonly ConversionStorage $storage)
    {
        //
    }

    public function convert(Attempt $attempt): void
    {
        $attempt->refresh();

        if ($attempt->status !== AttemptStatus::Pending) {
            return;
        }

        $this->markRunning($attempt);

        $inputFilename = $attempt->inputFilename();

        if ($inputFilename === null) {
            $this->fail($attempt, FailureCode::BridgeError);

            return;
        }

        $paths = $this->attemptPaths($attempt, $inputFilename);
        $bridge = $this->runBridge($attempt, $paths);
        $failureCode = $this->conversionFailureCode($bridge, $paths['pptx']);
        $this->writeLog($paths['log'], $bridge['output']);

        if ($failureCode !== null) {
            @unlink($paths['pptx']);
            $this->fail($attempt, $failureCode);

            return;
        }

        $pdfFailed = $this->renderPdf($attempt, $paths, $bridge['output']);
        $this->writeMetadata($attempt, $paths);
        $this->markReady($attempt, $paths, $pdfFailed);

        $this->storage->refreshConversionBytes($attempt->conversion);

        Log::info('attempt converted', [
            'attempt_id' => $attempt->id,
            'conversion_uuid' => $attempt->conversion->uuid,
            'status' => $attempt->status->value,
            'failure_code' => $attempt->failure_code,
            'input_bytes' => $attempt->input_bytes,
            'input_pixels' => $attempt->input_pixels,
            'pptx_bytes' => $attempt->pptx_bytes,
            'pdf_bytes' => $attempt->pdf_bytes,
        ]);
    }

    private function markRunning(Attempt $attempt): void
    {
        $attempt->forceFill([
            'status' => AttemptStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
        ])->save();
    }

    /**
     * @return array{attemptDir: string, input: string, pptx: string, pdf: string, log: string}
     */
    private function attemptPaths(Attempt $attempt, string $inputFilename): array
    {
        $attemptDir = $this->storage->attemptAbsoluteDirectory($attempt);
        File::ensureDirectoryExists($attemptDir);

        return [
            'attemptDir' => $attemptDir,
            'input' => $this->storage->absolutePath($attempt, $inputFilename),
            'pptx' => $this->storage->absolutePath($attempt, 'output.pptx'),
            'pdf' => $this->storage->absolutePath($attempt, 'output.pdf'),
            'log' => $this->storage->absolutePath($attempt, 'job.log'),
        ];
    }

    /**
     * @param  array{attemptDir: string, input: string, pptx: string, pdf: string, log: string}  $paths
     * @return array{exit_code: int|null, timed_out: bool, output: string}
     */
    private function runBridge(Attempt $attempt, array $paths): array
    {
        return $this->runProcess($attempt, [
            config('conversion.python'),
            config('conversion.bridge'),
            $paths['input'],
            '-o',
            $paths['pptx'],
            '--lang',
            'auto',
            '--max-inpaint-size',
            (string) config('conversion.max_inpaint_size'),
        ], $paths['attemptDir']);
    }

    /**
     * @param  array{exit_code: int|null, timed_out: bool, output: string}  $bridge
     */
    private function conversionFailureCode(array $bridge, string $pptx): ?FailureCode
    {
        return match (true) {
            $bridge['timed_out'] => FailureCode::BridgeTimeout,
            $bridge['exit_code'] === 137 => FailureCode::Oom,
            $bridge['exit_code'] !== 0 => FailureCode::BridgeError,
            ! is_file($pptx) || filesize($pptx) === 0 => FailureCode::EmptyOutput,
            ! $this->isValidZip($pptx) => FailureCode::InvalidPptx,
            default => null,
        };
    }

    /**
     * @param  array{attemptDir: string, input: string, pptx: string, pdf: string, log: string}  $paths
     */
    private function renderPdf(Attempt $attempt, array $paths, string $bridgeOutput): bool
    {
        if (! (bool) config('conversion.render_pdf')) {
            $this->writeLog($paths['log'], $bridgeOutput."\nPDF preview skipped; CONVERSION_RENDER_PDF=false.");

            return false;
        }

        $pdfResult = $this->runProcess($attempt, [
            config('conversion.soffice'),
            '--headless',
            '--convert-to',
            'pdf',
            '--outdir',
            $paths['attemptDir'],
            $paths['pptx'],
        ], $paths['attemptDir']);

        $this->writeLog($paths['log'], $bridgeOutput."\n".$pdfResult['output']);

        return $pdfResult['exit_code'] !== 0 || ! is_file($paths['pdf']) || filesize($paths['pdf']) === 0;
    }

    /**
     * @param  array{attemptDir: string, input: string, pptx: string, pdf: string, log: string}  $paths
     */
    private function writeMetadata(Attempt $attempt, array $paths): void
    {
        File::put($this->storage->absolutePath($attempt, 'meta.json'), json_encode([
            'byte_size' => $attempt->input_bytes,
            'hash_sha256' => hash_file('sha256', $paths['input']) ?: null,
            'lib_version' => 'px-image2pptx',
            'lang' => 'auto',
            'normalised_at' => now()->toISOString(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{attemptDir: string, input: string, pptx: string, pdf: string, log: string}  $paths
     */
    private function markReady(Attempt $attempt, array $paths, bool $pdfFailed): void
    {
        $renderPdf = (bool) config('conversion.render_pdf');

        $attempt->forceFill([
            'status' => AttemptStatus::Ready,
            'pptx_bytes' => filesize($paths['pptx']),
            'pdf_bytes' => (! $renderPdf || $pdfFailed) ? null : filesize($paths['pdf']),
            'pptx_sha256' => hash_file('sha256', $paths['pptx']) ?: null,
            'failure_code' => $renderPdf && $pdfFailed ? FailureCode::PdfRender->value : null,
            'failure_message' => $renderPdf && $pdfFailed ? FailureCode::PdfRender->message() : null,
            'heartbeat_at' => now(),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  array<int, string|null>  $command
     * @return array{exit_code: int|null, timed_out: bool, output: string}
     */
    private function runProcess(Attempt $attempt, array $command, string $cwd): array
    {
        $process = new Process(array_map(fn (?string $part): string => (string) $part, $command), $cwd);
        $process->setTimeout((float) config('conversion.timeout'));
        $output = '';
        $lastHeartbeat = now()->subSeconds(10);

        try {
            $process->start();

            while ($process->isRunning()) {
                $output .= $process->getIncrementalOutput();
                $output .= $process->getIncrementalErrorOutput();

                if ($lastHeartbeat->diffInSeconds(now()) >= 5) {
                    $attempt->forceFill(['heartbeat_at' => now()])->save();
                    $lastHeartbeat = now();
                }

                usleep(250_000);
                $process->checkTimeout();
            }

            $output .= $process->getOutput();
            $output .= $process->getErrorOutput();

            return [
                'exit_code' => $process->getExitCode(),
                'timed_out' => false,
                'output' => $output,
            ];
        } catch (ProcessTimedOutException) {
            $process->stop(2, SIGKILL);

            return [
                'exit_code' => null,
                'timed_out' => true,
                'output' => $output,
            ];
        } catch (\Throwable $throwable) {
            return [
                'exit_code' => 1,
                'timed_out' => false,
                'output' => $output."\n".$throwable->getMessage(),
            ];
        }
    }

    private function writeLog(string $path, string $contents): void
    {
        File::put($path, substr($contents, 0, (int) config('conversion.log_limit')));
    }

    private function fail(Attempt $attempt, FailureCode $code): void
    {
        $attempt->forceFill([
            'status' => AttemptStatus::Failed,
            'failure_code' => $code->value,
            'failure_message' => $code->message(),
            'heartbeat_at' => now(),
            'finished_at' => now(),
        ])->save();

        $this->storage->refreshConversionBytes($attempt->conversion);
    }

    private function isValidZip(string $path): bool
    {
        if (! class_exists(\ZipArchive::class)) {
            return true;
        }

        $zip = new \ZipArchive;
        $opened = $zip->open($path);

        if ($opened !== true) {
            return false;
        }

        $result = $zip->numFiles > 0;
        $zip->close();

        return $result;
    }
}
