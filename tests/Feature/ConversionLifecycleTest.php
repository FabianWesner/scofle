<?php

use App\AttemptStatus;
use App\Models\Attempt;
use App\Models\Conversion;
use App\Models\Session;
use App\Services\ImageSessionManager;
use Illuminate\Support\Facades\Storage;

test('regenerate creates a new attempt on the same conversion', function () {
    configureSuccessfulConverters();

    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['image' => pngUpload('first.png'), 'nonce' => $home->viewData('page')['props']['uploadNonce']]);
    $conversion = Conversion::query()->firstOrFail();

    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post(route('conversions.regenerate', $conversion))
        ->assertRedirect(route('conversions.show', $conversion));

    expect($conversion->fresh()->attempts()->count())->toBe(2)
        ->and(storage_path("app/private/tmp/sessions/{$conversion->session_id}/conversions/{$conversion->uuid}/attempts/2/input.png"))->toBeFile();
});

test('uploading another image creates a separate conversion in the same session', function () {
    configureSuccessfulConverters();

    $firstHome = $this->get('/');
    $cookie = imageSessionCookie($firstHome);
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['image' => pngUpload('first.png'), 'nonce' => $firstHome->viewData('page')['props']['uploadNonce']]);
    $secondHome = $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)->get('/');
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['image' => pngUpload('second.png'), 'nonce' => $secondHome->viewData('page')['props']['uploadNonce']]);

    expect(Conversion::query()->count())->toBe(2)
        ->and(Session::query()->count())->toBe(1)
        ->and(Attempt::query()->count())->toBe(2);
});

test('delete conversion removes rows and private files', function () {
    configureSuccessfulConverters();

    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['image' => pngUpload(), 'nonce' => $home->viewData('page')['props']['uploadNonce']]);
    $conversion = Conversion::query()->firstOrFail();
    $path = storage_path("app/private/tmp/sessions/{$conversion->session_id}/conversions/{$conversion->uuid}");

    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->delete(route('conversions.destroy', $conversion))
        ->assertRedirect(route('home'));

    expect(Conversion::query()->count())->toBe(0)
        ->and(Attempt::query()->count())->toBe(0)
        ->and(is_dir($path))->toBeFalse();
});

test('reaper deletes expired conversions using created_at and skips in-flight conversions', function () {
    $expired = Conversion::factory()
        ->has(Attempt::factory()->ready())
        ->create(['created_at' => now()->subHours(25), 'updated_at' => now()]);
    $running = Conversion::factory()
        ->has(Attempt::factory()->state(['status' => AttemptStatus::Running, 'heartbeat_at' => now()]))
        ->create(['created_at' => now()->subHours(25)]);

    Storage::disk('local')->put("tmp/sessions/{$expired->session_id}/conversions/{$expired->uuid}/attempts/1/input.png", validPngBytes());
    Storage::disk('local')->put("tmp/sessions/{$running->session_id}/conversions/{$running->uuid}/attempts/1/input.png", validPngBytes());

    $this->artisan('conversions:reap')->assertSuccessful();

    expect(Conversion::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(Conversion::query()->whereKey($running->id)->exists())->toBeTrue();
});

test('session keeps at most configured conversions by evicting oldest evictable conversion', function () {
    configureSuccessfulConverters();

    config(['conversion.max_per_session' => 2]);

    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $session = Session::query()->firstOrFail();

    for ($i = 0; $i < 2; $i++) {
        Conversion::factory()
            ->has(Attempt::factory()->ready())
            ->create([
                'session_id' => $session->id,
                'created_at' => now()->subMinutes(30 - $i),
            ]);
    }

    $nonce = $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)->get('/')->viewData('page')['props']['uploadNonce'];
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['image' => pngUpload(), 'nonce' => $nonce])
        ->assertRedirect();

    expect($session->fresh()->conversions()->count())->toBe(2)
        ->and(Conversion::query()->count())->toBe(2);
});
