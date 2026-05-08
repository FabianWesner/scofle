<?php

use App\AttemptStatus;
use App\Models\Attempt;
use App\Models\Conversion;
use App\Models\Session;
use App\Services\ImageSessionManager;
use Inertia\Testing\AssertableInertia as Assert;

test('home page renders upload shell with secure session cookie and headers', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('uploadNonce')
            ->where('ttlHours', 24)
            ->has('recentConversions', 0));

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === ImageSessionManager::CookieName);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

test('valid png upload creates session conversion, stores private input, converts, and renders page', function () {
    configureSuccessfulConverters();

    $home = $this->get('/');
    $nonce = $home->viewData('page')['props']['uploadNonce'];
    $cookie = imageSessionCookie($home);

    $response = $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)->post('/uploads', [
        'image' => pngUpload('deck.png'),
        'nonce' => $nonce,
    ]);

    $session = Session::query()->firstOrFail();
    $conversion = Conversion::query()->firstOrFail();
    $attempt = Attempt::query()->firstOrFail();

    $response->assertRedirect(route('conversions.show', $conversion));

    expect($conversion->session_id)->toBe($session->id)
        ->and($attempt->conversion_id)->toBe($conversion->id)
        ->and($attempt->n)->toBe(1)
        ->and($attempt->status)->toBe(AttemptStatus::Ready)
        ->and($attempt->input_ext)->toBe('png')
        ->and($attempt->pptx_bytes)->toBeGreaterThan(0)
        ->and($attempt->pdf_bytes)->toBeGreaterThan(0);

    $base = storage_path("app/private/tmp/sessions/{$session->id}/conversions/{$conversion->uuid}/attempts/1");
    expect($base.'/input.png')->toBeFile()
        ->and($base.'/output.pptx')->toBeFile()
        ->and($base.'/output.pdf')->toBeFile()
        ->and($base.'/job.log')->toBeFile()
        ->and($base.'/meta.json')->toBeFile();

    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)->get(route('conversions.show', $conversion))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('conversion')
            ->where('conversion.uuid', $conversion->uuid)
            ->where('selectedAttempt.displayStatus', 'ready')
            ->where('selectedAttempt.displayFilename', 'deck.png')
            ->has('selectedAttempt.downloads.pptx')
            ->has('selectedAttempt.downloads.pdf')
            ->has('attempts', 1));
});

test('different sessions cannot open conversion URLs', function () {
    configureSuccessfulConverters();

    $home = $this->get('/');
    $nonce = $home->viewData('page')['props']['uploadNonce'];
    $cookie = imageSessionCookie($home);
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)->post('/uploads', ['image' => pngUpload(), 'nonce' => $nonce]);
    $conversion = Conversion::query()->firstOrFail();

    $this->withUnencryptedCookie(ImageSessionManager::CookieName, str_repeat('a', 80))
        ->get(route('conversions.show', $conversion))
        ->assertNotFound();
});
