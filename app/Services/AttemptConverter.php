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

        $attempt->forceFill([
            'status' => AttemptStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
        ])->save();

        $inputFilename = $attempt->inputFilename();

        if ($inputFilename === null) {
            $this->fail($attempt, FailureCode::BridgeError);

            return;
        }

        $attemptDir = $this->storage->attemptAbsoluteDirectory($attempt);
        File::ensureDirectoryExists($attemptDir);

        $input = $this->storage->absolutePath($attempt, $inputFilename);
        $pptx = $this->storage->absolutePath($attempt, 'output.pptx');
        $pdf = $this->storage->absolutePath($attempt, 'output.pdf');
        $log = $this->storage->absolutePath($attempt, 'job.log');

        $bridge = $this->runProcess($attempt, [
            config('conversion.python'),
            config('conversion.bridge'),
            $input,
            '-o',
            $pptx,
            '--lang',
            'auto',
            '--max-inpaint-size',
            (string) config('conversion.max_inpaint_size'),
        ], $attemptDir);

        $this->writeLog($log, $bridge['output']);

        if ($bridge['timed_out']) {
            $this->fail($attempt, FailureCode::BridgeTimeout);

            return;
        }

        if ($bridge['exit_code'] === 137) {
            $this->fail($attempt, FailureCode::Oom);

            return;
        }

        if ($bridge['exit_code'] !== 0) {
            $this->fail($attempt, FailureCode::BridgeError);

            return;
        }

        if (! is_file($pptx) || filesize($pptx) === 0) {
            @unlink($pptx);
            $this->fail($attempt, FailureCode::EmptyOutput);

            return;
        }

        if (! $this->isValidZip($pptx)) {
            @unlink($pptx);
            $this->fail($attempt, FailureCode::InvalidPptx);

            return;
        }

        $renderPdf = (bool) config('conversion.render_pdf');
        $pdfFailed = false;

        if ($renderPdf) {
            $pdfResult = $this->runProcess($attempt, [
                config('conversion.soffice'),
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $attemptDir,
                $pptx,
            ], $attemptDir);

            $this->writeLog($log, $bridge['output']."\n".$pdfResult['output']);

            $pdfFailed = $pdfResult['exit_code'] !== 0 || ! is_file($pdf) || filesize($pdf) === 0;
        } else {
            $this->writeLog($log, $bridge['output']."\nPDF preview skipped; CONVERSION_RENDER_PDF=false.");
        }

        $pptxBytes = filesize($pptx);
        $pdfBytes = (! $renderPdf || $pdfFailed) ? null : filesize($pdf);

        $metaPath = $this->storage->absolutePath($attempt, 'meta.json');
        File::put($metaPath, json_encode([
            'byte_size' => $attempt->input_bytes,
            'hash_sha256' => hash_file('sha256', $input) ?: null,
            'lib_version' => 'px-image2pptx',
            'lang' => 'auto',
            'normalised_at' => now()->toISOString(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $attempt->forceFill([
            'status' => AttemptStatus::Ready,
            'pptx_bytes' => $pptxBytes,
            'pdf_bytes' => $pdfBytes,
            'pptx_sha256' => hash_file('sha256', $pptx) ?: null,
            'failure_code' => $renderPdf && $pdfFailed ? FailureCode::PdfRender->value : null,
            'failure_message' => $renderPdf && $pdfFailed ? FailureCode::PdfRender->message() : null,
            'heartbeat_at' => now(),
            'finished_at' => now(),
        ])->save();

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
