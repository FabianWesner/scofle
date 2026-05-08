<?php

use App\Services\ImageSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
        config(['queue.default' => 'sync']);
        Queue::setDefaultDriver('sync');
    })
    ->afterEach(function (): void {
        File::deleteDirectory(storage_path('app/private/tmp/sessions'));
    })
    ->in('Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function validPngBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
}

function pngUpload(string $name = 'slide.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, validPngBytes());
}

function imageSessionCookie(TestResponse $response): string
{
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === ImageSessionManager::CookieName);

    expect($cookie)->not->toBeNull();

    return (string) $cookie->getValue();
}

function configureSuccessfulConverters(): void
{
    $dir = storage_path('framework/testing/converters');
    File::ensureDirectoryExists($dir);

    $bridge = $dir.'/fake-bridge.php';
    $soffice = $dir.'/fake-soffice.php';

    File::put($bridge, <<<'PHP'
<?php

$outputIndex = array_search('-o', $argv, true);
$output = $argv[$outputIndex + 1] ?? null;

if (! is_string($output)) {
    fwrite(STDERR, 'No output path');
    exit(1);
}

$zip = new ZipArchive();
$zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
$zip->addFromString('ppt/presentation.xml', '<presentation/>');
$zip->close();

exit(0);
PHP);

    File::put($soffice, <<<'PHP'
#!/usr/bin/env php
<?php

$outdirIndex = array_search('--outdir', $argv, true);
$outdir = $argv[$outdirIndex + 1] ?? null;

if (! is_string($outdir)) {
    fwrite(STDERR, 'No outdir');
    exit(1);
}

file_put_contents($outdir.'/output.pdf', "%PDF-1.4\n%%EOF\n");

exit(0);
PHP);

    chmod($soffice, 0755);

    config([
        'conversion.python' => PHP_BINARY,
        'conversion.bridge' => $bridge,
        'conversion.soffice' => $soffice,
        'conversion.render_pdf' => true,
    ]);
}
