<?php

use App\AttemptStatus;
use App\Models\Attempt;
use App\Models\Conversion;
use App\Models\Session;
use App\Services\ConversionStorage;
use App\Services\ImageSessionManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;

test('downloads require valid signatures, owning session, and expected headers', function () {
    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $session = Session::query()->firstOrFail();
    $conversion = Conversion::factory()->create(['session_id' => $session->id]);
    $attempt = Attempt::factory()->ready()->create([
        'conversion_id' => $conversion->id,
        'n' => 1,
        'pptx_bytes' => 100,
        'pdf_bytes' => 20,
    ]);

    $dir = storage_path("app/private/tmp/sessions/{$session->id}/conversions/{$conversion->uuid}/attempts/1");
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/output.pptx', 'pptx');
    file_put_contents($dir.'/output.pdf', "%PDF-1.4\n%%EOF\n");
    app(ConversionStorage::class)->refreshConversionBytes($conversion);

    $this->withUnencryptedCookie(ImageSessionManager::COOKIE_NAME, $cookie)
        ->get(route('downloads.show', [$attempt, 'pptx']))
        ->assertForbidden();

    $url = URL::temporarySignedRoute('downloads.show', now()->addHour(), ['attempt' => $attempt->id, 'kind' => 'pptx']);

    $this->withUnencryptedCookie(ImageSessionManager::COOKIE_NAME, $cookie)
        ->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.presentationml.presentation')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $otherSession = Session::factory()->create();
    $this->withUnencryptedCookie(ImageSessionManager::COOKIE_NAME, $otherSession->token)
        ->get($url)
        ->assertNotFound();

    $inlinePdf = URL::temporarySignedRoute('downloads.show', now()->addHour(), ['attempt' => $attempt->id, 'kind' => 'pdf', 'inline' => 1]);

    $this->withUnencryptedCookie(ImageSessionManager::COOKIE_NAME, $cookie)
        ->get($inlinePdf)
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox')
        ->assertHeader('Content-Disposition', 'inline; filename="conversion-'.substr($conversion->uuid, 0, 8).'-a1.pdf"');
});

test('non-ready attempts cannot be downloaded', function () {
    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $session = Session::query()->firstOrFail();
    $conversion = Conversion::factory()->create(['session_id' => $session->id]);
    $attempt = Attempt::factory()->create([
        'conversion_id' => $conversion->id,
        'status' => AttemptStatus::Running,
        'pptx_bytes' => 100,
    ]);

    $url = URL::temporarySignedRoute('downloads.show', now()->addHour(), ['attempt' => $attempt->id, 'kind' => 'pptx']);

    $this->withUnencryptedCookie(ImageSessionManager::COOKIE_NAME, $cookie)
        ->get($url)
        ->assertNotFound();
});
