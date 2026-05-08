<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImageUploadValidator
{
    /**
     * @return array{mime: 'image/png'|'image/jpeg', ext: 'png'|'jpg', bytes: int, pixels: int, width: int, height: int}
     */
    public function validate(UploadedFile $file, string $errorKey = 'images.0'): array
    {
        $path = $file->getRealPath();

        if (! is_string($path) || ! is_file($path) || filesize($path) === 0) {
            $this->fail('The uploaded file is empty.', $errorKey);
        }

        $mime = $this->sniffMime($path, $errorKey);
        $this->rejectKnownUnsupported($path, $mime, $errorKey);

        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            $this->fail('Only PNG and JPEG images are accepted.', $errorKey);
        }

        $declaredExtension = Str::lower($file->getClientOriginalExtension());
        $canonicalExtension = $this->canonicalExtension($mime);

        if (
            ! in_array($declaredExtension, ['png', 'jpg', 'jpeg'], true)
            || ($mime === 'image/png' && $declaredExtension !== 'png')
            || ($mime === 'image/jpeg' && ! in_array($declaredExtension, ['jpg', 'jpeg'], true))
        ) {
            $this->fail('File contents do not match the file extension.', $errorKey);
        }

        $size = @getimagesize($path);

        if ($size === false) {
            $this->fail('We could not read this image. Use a valid PNG or JPEG.', $errorKey);
        }

        [$width, $height] = $size;
        $longEdge = max($width, $height);
        $pixels = $width * $height;

        if ($longEdge > config('upload.max_long_edge')) {
            $this->fail('Image dimensions are too large. The longest side must be 4096 px or less.', $errorKey);
        }

        if ($pixels > config('upload.max_pixels')) {
            $this->fail('Image has too many pixels. Upload an image up to 16,777,216 total pixels.', $errorKey);
        }

        return [
            'mime' => $mime,
            'ext' => $canonicalExtension,
            'bytes' => (int) filesize($path),
            'pixels' => $pixels,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function sniffMime(string $path, string $errorKey): string
    {
        $info = finfo_open(FILEINFO_MIME_TYPE);

        if ($info === false) {
            $this->fail('We could not inspect this file.', $errorKey);
        }

        $mime = finfo_file($info, $path);
        finfo_close($info);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    private function rejectKnownUnsupported(string $path, string $mime, string $errorKey): void
    {
        $prefix = file_get_contents($path, false, null, 0, 512) ?: '';
        $lowerPrefix = Str::lower($prefix);

        if (str_starts_with($prefix, 'GIF8') || $mime === 'image/gif') {
            $this->fail('GIF is not supported. Use PNG or JPEG.', $errorKey);
        }

        if (str_starts_with($prefix, 'BM') || $mime === 'image/bmp' || $mime === 'image/x-ms-bmp') {
            $this->fail('BMP is not supported. Use PNG or JPEG.', $errorKey);
        }

        if (str_starts_with($prefix, "II*\0") || str_starts_with($prefix, "MM\0*") || in_array($mime, ['image/tiff', 'image/x-tiff'], true)) {
            $this->fail('TIFF is not supported. Use PNG or JPEG.', $errorKey);
        }

        if (str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WEBP') {
            $this->fail('WebP is not supported. Convert to PNG or JPEG.', $errorKey);
        }

        if (str_contains($lowerPrefix, '<svg') || $mime === 'image/svg+xml') {
            $this->fail('SVG is not supported. Use PNG or JPEG.', $errorKey);
        }

        if (str_contains(substr($prefix, 4, 16), 'ftypheic') || str_contains(substr($prefix, 4, 16), 'ftypheif') || str_contains(substr($prefix, 4, 16), 'ftypmif1')) {
            $this->fail('HEIC is not supported. Use PNG or JPEG.', $errorKey);
        }
    }

    private function canonicalExtension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => 'bin',
        };
    }

    private function fail(string $message, string $errorKey): never
    {
        throw ValidationException::withMessages([
            $errorKey => $message,
        ]);
    }
}
