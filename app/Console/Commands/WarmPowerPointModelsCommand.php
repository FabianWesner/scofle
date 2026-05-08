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
        $soffice = (string) config('conversion.soffice');

        if (! is_file($bridge)) {
            $this->error("Bridge wrapper not found: {$bridge}");

            return self::FAILURE;
        }

        if (! $this->checkPython($python) || ! $this->checkSoffice($soffice)) {
            return self::FAILURE;
        }

        if ($this->option('check-only')) {
            $this->info('PowerPoint conversion prerequisites are present.');

            return self::SUCCESS;
        }

        return $this->warm($python, $bridge) ? self::SUCCESS : self::FAILURE;
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

        if ($result->getExitCode() !== 0 || ! preg_match('/(LibreOffice|soffice)\s+([7-9]|\d{2,})\./i', $versionOutput)) {
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
