<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

#[Signature('ppt:warm-models {--check-only : Only verify local prerequisites without running the ML warm-up.}')]
#[Description('Warm PaddleOCR and inpainting models for px-image2pptx.')]
class WarmPowerPointModelsCommand extends Command
{
    public function handle(): int
    {
        $python = (string) config('conversion.python');
        $bridge = (string) config('conversion.bridge');
        $ready = $this->checkBridge($bridge)
            && $this->checkPython($python)
            && $this->checkPdfRenderer();

        if ($ready && $this->option('check-only')) {
            $this->info($this->checkOnlyMessage());
        }

        if ($ready && ! $this->option('check-only')) {
            $ready = $this->warm($python, $bridge);
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    private function checkBridge(string $bridge): bool
    {
        if (is_file($bridge)) {
            return true;
        }

        $this->error("Bridge wrapper not found: {$bridge}");

        return false;
    }

    private function checkPdfRenderer(): bool
    {
        return ! (bool) config('conversion.render_pdf') || $this->checkSoffice((string) config('conversion.soffice'));
    }

    private function checkOnlyMessage(): string
    {
        return (bool) config('conversion.render_pdf')
            ? 'PowerPoint conversion and PDF preview prerequisites are present.'
            : 'PowerPoint conversion prerequisites are present. PDF preview is disabled.';
    }

    private function checkPython(string $python): bool
    {
        if (! is_file($python) || ! is_executable($python)) {
            $this->error("Python executable not found or not executable: {$python}");

            return false;
        }

        $result = new Process([$python, '--version']);
        $result->run();
        $versionOutput = trim($result->getOutput().' '.$result->getErrorOutput());

        if ($result->getExitCode() !== 0 || ! preg_match('/Python\s+3\.(9|10|11|12|13)\./', $versionOutput)) {
            $this->error("Python 3.9 through 3.13 is required; saw {$versionOutput}");

            return false;
        }

        return true;
    }

    private function checkSoffice(string $soffice): bool
    {
        $result = new Process([$soffice, '--version']);
        $result->run();
        $versionOutput = trim($result->getOutput().' '.$result->getErrorOutput());

        $versionIsSupported = preg_match('/(?:LibreOffice|soffice)\s+(\d+)\./i', $versionOutput, $matches)
            && (int) $matches[1] >= 7;

        if ($result->getExitCode() !== 0 || ! $versionIsSupported) {
            $this->error("LibreOffice 7+ is required; saw {$versionOutput}");

            return false;
        }

        return true;
    }

    private function warm(string $python, string $bridge): bool
    {
        $dir = storage_path('app/private/warm-models');
        File::ensureDirectoryExists($dir);

        $input = $dir.'/input.png';
        $output = $dir.'/output.pptx';
        File::put($input, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAS0lEQVR42u3PMQ0AAAwDoPo33UrYvQQckD4XAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAYHLAMpT0sIcNbcEAAAAAElFTkSuQmCC'));
        @unlink($output);

        $process = new Process([
            $python,
            $bridge,
            $input,
            '-o',
            $output,
            '--lang',
            'auto',
            '--max-inpaint-size',
            (string) config('conversion.max_inpaint_size'),
        ], $dir);
        $process->setTimeout((float) config('conversion.timeout'));
        $process->run();

        if ($process->getExitCode() !== 0 || ! is_file($output) || filesize($output) === 0) {
            $this->error(trim($process->getOutput()."\n".$process->getErrorOutput()));

            return false;
        }

        $this->info('PowerPoint models warmed.');

        return true;
    }
}
