<?php

use App\AttemptStatus;
use App\Jobs\ProcessConversionQueueJob;
use App\Models\Attempt;
use App\Models\Conversion;
use App\Models\Session;
use App\Services\AttemptConverter;
use App\Services\ImageSessionManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('regenerate creates a new attempt on the same conversion', function () {
    configureSuccessfulConverters();

    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['images' => [pngUpload('first.png')], 'nonce' => $home->viewData('page')['props']['uploadNonce']]);
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
        ->post('/uploads', ['images' => [pngUpload('first.png')], 'nonce' => $firstHome->viewData('page')['props']['uploadNonce']]);
    $secondHome = $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)->get('/');
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['images' => [pngUpload('second.png')], 'nonce' => $secondHome->viewData('page')['props']['uploadNonce']]);

    expect(Conversion::query()->count())->toBe(2)
        ->and(Session::query()->count())->toBe(1)
        ->and(Attempt::query()->count())->toBe(2);
});

test('delete conversion removes rows and private files', function () {
    configureSuccessfulConverters();

    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['images' => [pngUpload()], 'nonce' => $home->viewData('page')['props']['uploadNonce']]);
    $conversion = Conversion::query()->firstOrFail();
    $path = storage_path("app/private/tmp/sessions/{$conversion->session_id}/conversions/{$conversion->uuid}");

    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->delete(route('conversions.destroy', $conversion))
        ->assertRedirect(route('home'));

    expect(Conversion::query()->count())->toBe(0)
        ->and(Attempt::query()->count())->toBe(0)
        ->and(is_dir($path))->toBeFalse();
});

test('delete all conversions removes only current session rows and private files', function () {
    configureSuccessfulConverters();

    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['images' => [pngUpload('first.png')], 'nonce' => $home->viewData('page')['props']['uploadNonce']]);

    $secondHome = $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)->get('/');
    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->post('/uploads', ['images' => [pngUpload('second.png')], 'nonce' => $secondHome->viewData('page')['props']['uploadNonce']]);

    $session = Session::query()->firstOrFail();
    $paths = $session->conversions()
        ->get()
        ->map(fn (Conversion $conversion): string => storage_path("app/private/tmp/sessions/{$session->id}/conversions/{$conversion->uuid}"))
        ->all();
    Storage::disk('local')->put("tmp/sessions/{$session->id}/conversions/orphaned/attempts/1/input.png", validPngBytes());
    $orphanPath = storage_path("app/private/tmp/sessions/{$session->id}/conversions/orphaned");
    $otherConversion = Conversion::factory()
        ->for(Session::factory())
        ->has(Attempt::factory()->ready())
        ->create();

    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->delete(route('conversions.destroy-all'))
        ->assertRedirect(route('home'));

    expect($session->fresh()->conversions()->count())->toBe(0)
        ->and(Conversion::query()->whereKey($otherConversion->id)->exists())->toBeTrue()
        ->and(Attempt::query()->whereBelongsTo($otherConversion)->exists())->toBeTrue();

    foreach ($paths as $path) {
        expect(is_dir($path))->toBeFalse();
    }

    expect(is_dir($orphanPath))->toBeFalse();
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
    Storage::disk('local')->put('tmp/sessions/999/conversions/orphaned/attempts/1/input.png', validPngBytes());

    $this->artisan('conversions:reap')->assertSuccessful();

    expect(Conversion::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(Conversion::query()->whereKey($running->id)->exists())->toBeTrue()
        ->and(is_dir(storage_path('app/private/tmp/sessions/999/conversions/orphaned')))->toBeFalse();
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
        ->post('/uploads', ['images' => [pngUpload()], 'nonce' => $nonce])
        ->assertRedirect();

    expect($session->fresh()->conversions()->count())->toBe(2)
        ->and(Conversion::query()->count())->toBe(2);
});

test('multiple image upload creates queued conversions and dispatches one processor', function () {
    Queue::fake();

    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $nonce = $home->viewData('page')['props']['uploadNonce'];

    $response = $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)->post('/uploads', [
        'images' => [
            pngUpload('first.png'),
            pngUpload('second.png'),
        ],
        'nonce' => $nonce,
    ]);

    $conversions = Conversion::query()->with('attempts')->orderBy('id')->get();

    $response->assertRedirect(route('conversions.show', $conversions->first()));

    expect($conversions)->toHaveCount(2)
        ->and(Attempt::query()->count())->toBe(2)
        ->and(Attempt::query()->orderBy('id')->get()->pluck('status')->all())->toBe([AttemptStatus::Pending, AttemptStatus::Pending]);

    Queue::assertPushed(ProcessConversionQueueJob::class, 1);

    $recent = $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->get('/')
        ->viewData('page')['props']['recentConversions'];

    expect($recent)->toHaveCount(2)
        ->and(collect($recent)->pluck('displayStatus')->all())->toBe(['pending', 'pending']);
});

test('json upload returns redirect and next nonce for client-side batch queues', function () {
    Queue::fake();

    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $nonce = $home->viewData('page')['props']['uploadNonce'];

    $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
        ->withHeader('Accept', 'application/json')
        ->post('/uploads', ['images' => [pngUpload('first.png')], 'nonce' => $nonce])
        ->assertCreated()
        ->assertJsonStructure(['redirect', 'uploadNonce']);

    expect(Conversion::query()->count())->toBe(1)
        ->and(Attempt::query()->count())->toBe(1);

    Queue::assertPushed(ProcessConversionQueueJob::class, 1);
});

test('client-side batch can create multiple conversions with rotating upload nonces', function () {
    Queue::fake();

    $home = $this->get('/');
    $cookie = imageSessionCookie($home);
    $nonce = $home->viewData('page')['props']['uploadNonce'];

    foreach (['first.png', 'second.png'] as $filename) {
        $response = $this->withUnencryptedCookie(ImageSessionManager::CookieName, $cookie)
            ->withHeader('Accept', 'application/json')
            ->post('/uploads', ['images' => [pngUpload($filename)], 'nonce' => $nonce])
            ->assertCreated();

        $nonce = $response->json('uploadNonce');
    }

    expect(Conversion::query()->count())->toBe(2)
        ->and(Attempt::query()->count())->toBe(2);

    Queue::assertPushed(ProcessConversionQueueJob::class, 2);
});

test('processor drains pending attempts in first-in first-out order', function () {
    $first = Attempt::factory()
        ->for(Conversion::factory())
        ->create(['status' => AttemptStatus::Pending, 'created_at' => now()->subMinute()]);
    $second = Attempt::factory()
        ->for(Conversion::factory())
        ->create(['status' => AttemptStatus::Pending, 'created_at' => now()]);
    $processed = [];
    $converter = Mockery::mock(AttemptConverter::class);

    $converter
        ->shouldReceive('convert')
        ->twice()
        ->andReturnUsing(function (Attempt $attempt) use (&$processed): void {
            $processed[] = $attempt->id;
            $attempt->forceFill(['status' => AttemptStatus::Ready])->save();
        });

    (new ProcessConversionQueueJob)->handle($converter);

    expect($processed)->toBe([$first->id, $second->id]);
});
