<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Conversion;
use App\Models\Session;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ConversionStorage
{
    public function storeInput(Attempt $attempt, UploadedFile $file): string
    {
        $filename = $attempt->inputFilename();

        if ($filename === null) {
            throw new \RuntimeException('Attempt input extension is missing.');
        }

        $path = $this->attemptRelativeDirectory($attempt).'/'.$filename;
        $sourcePath = $file->getRealPath();

        if (! is_string($sourcePath)) {
            throw new \RuntimeException('Uploaded file is not readable.');
        }

        Storage::disk('local')->put($path, (string) file_get_contents($sourcePath));

        return $this->absolutePath($attempt, $filename);
    }

    public function copyInput(Attempt $source, Attempt $target): string
    {
        $sourceFilename = $source->inputFilename();
        $targetFilename = $target->inputFilename();

        if ($sourceFilename === null || $targetFilename === null) {
            throw new \RuntimeException('Attempt input extension is missing.');
        }

        Storage::disk('local')->copy(
            $this->attemptRelativeDirectory($source).'/'.$sourceFilename,
            $this->attemptRelativeDirectory($target).'/'.$targetFilename,
        );

        return $this->absolutePath($target, $targetFilename);
    }

    public function absolutePath(Attempt $attempt, string $filename): string
    {
        return Storage::disk('local')->path($this->attemptRelativeDirectory($attempt).'/'.$filename);
    }

    public function attemptAbsoluteDirectory(Attempt $attempt): string
    {
        return Storage::disk('local')->path($this->attemptRelativeDirectory($attempt));
    }

    public function attemptRelativeDirectory(Attempt $attempt): string
    {
        $attempt->loadMissing('conversion');

        return "tmp/sessions/{$attempt->conversion->session_id}/conversions/{$attempt->conversion->uuid}/attempts/{$attempt->n}";
    }

    public function deleteConversion(Conversion $conversion): void
    {
        Storage::disk('local')->deleteDirectory("tmp/sessions/{$conversion->session_id}/conversions/{$conversion->uuid}");
    }

    public function deleteSessionConversions(Session $session): void
    {
        Storage::disk('local')->deleteDirectory("tmp/sessions/{$session->id}/conversions");
    }

    public function deleteAttempt(Attempt $attempt): void
    {
        Storage::disk('local')->deleteDirectory($this->attemptRelativeDirectory($attempt));
    }

    public function refreshConversionBytes(Conversion $conversion): int
    {
        $path = Storage::disk('local')->path("tmp/sessions/{$conversion->session_id}/conversions/{$conversion->uuid}");
        $bytes = 0;

        if (is_dir($path)) {
            foreach (File::allFiles($path) as $file) {
                $bytes += $file->getSize();
            }
        }

        $conversion->forceFill(['total_bytes' => $bytes])->save();

        return $bytes;
    }
}
