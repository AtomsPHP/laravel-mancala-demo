<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        // routes/api.php is deliberately NOT registered as `api:`. The player's
        // seat key lives in the session — it is signed into the WebSocket ticket
        // as a claim, so it must never be something the browser can choose —
        // which makes these stateful, cookie-authenticated routes that happen to
        // answer JSON, not a public API. The web group gives them the session
        // and CSRF together; the stateless api group would give them neither.
        then: static function (): void {
            Route::middleware('web')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(static function (Middleware $middleware): void {
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
    })
    ->create();
