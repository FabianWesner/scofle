<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureImageSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\ImageSessionManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            ImageSessionManager::COOKIE_NAME,
        ]);

        $middleware->web(append: [
            EnsureImageSession::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AddSecurityHeaders::class,
        ]);
    })
    ->withExceptions()
    ->create();
