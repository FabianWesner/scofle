<?php

use App\Models\Conversion;
use App\Services\ImageSessionManager;
use Illuminate\Http\UploadedFile;

test('extension and sniffed mime must agree', function () {
    $nonce = $this->get('/')->viewData('page')['props']['uploadNonce'];

    $this->post('/uploads', [
        'images' => [UploadedFile::fake()->createWithContent('renamed.jpg', validPngBytes())],
        'nonce' => $nonce,
    ])->assertSessionHasErrors(['images.0' => 'File contents do not match the file extension.']);

    expect(Conversion::query()->count())->toBe(0);
});

test('known unsupported formats return specific validation messages', function (string $filename, string $contents, string $message) {
    $nonce = $this->get('/')->viewData('page')['props']['uploadNonce'];

    $this->post('/uploads', [
        'images' => [UploadedFile::fake()->createWithContent($filename, $contents)],
        'nonce' => $nonce,
    ])->assertSessionHasErrors(['images.0' => $message]);
})->with([
    'webp' => ['image.webp', 'RIFFxxxxWEBP', 'WebP is not supported. Convert to PNG or JPEG.'],
    'gif' => ['image.gif', 'GIF89a', 'GIF is not supported. Use PNG or JPEG.'],
    'svg' => ['image.svg', '<svg><script /></svg>', 'SVG is not supported. Use PNG or JPEG.'],
    'bmp' => ['image.bmp', 'BMfake', 'BMP is not supported. Use PNG or JPEG.'],
    'tiff' => ['image.tiff', "II*\0fake", 'TIFF is not supported. Use PNG or JPEG.'],
    'heic' => ['image.heic', "\0\0\0\0ftypheic", 'HEIC is not supported. Use PNG or JPEG.'],
]);

test('image dimensions are capped before any conversion is created', function () {
    $nonce = $this->get('/')->viewData('page')['props']['uploadNonce'];

    $this->post('/uploads', [
        'images' => [UploadedFile::fake()->image('too-wide.png', 4097, 100)],
        'nonce' => $nonce,
    ])->assertSessionHasErrors(['images.0' => 'Image dimensions are too large. The longest side must be 4096 px or less.']);

    expect(Conversion::query()->count())->toBe(0);
});

test('upload nonce is single use', function () {
    configureSuccessfulConverters();

    $home = $this->get('/');
    $nonce = $home->viewData('page')['props']['uploadNonce'];
    $cookie = imageSessionCookie($home);
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['images' => [pngUpload()], 'nonce' => $nonce])
        ->assertRedirect();

    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['images' => [pngUpload('again.png')], 'nonce' => $nonce])
        ->assertSessionHasErrors(['images' => 'This upload was already submitted. Refresh and try again.']);

    expect(Conversion::query()->count())->toBe(1);
});

test('batch upload count is capped before conversion rows are created', function () {
    config(['conversion.max_batch_uploads' => 2]);
    $nonce = $this->get('/')->viewData('page')['props']['uploadNonce'];

    $this->post('/uploads', [
        'images' => [
            pngUpload('first.png'),
            pngUpload('second.png'),
            pngUpload('third.png'),
        ],
        'nonce' => $nonce,
    ])->assertSessionHasErrors(['images' => 'Upload at most 2 images at once.']);

    expect(Conversion::query()->count())->toBe(0);
});
