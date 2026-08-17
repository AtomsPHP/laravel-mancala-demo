<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Keyed on the session rather than the IP: this app sits behind a proxy
        // it does not configure as trusted, so every visitor can look like one
        // address and share a single bucket.
        RateLimiter::for('tickets', static fn (Request $request): Limit => Limit::perMinute(
            (int) config('mancala.ticket_mints_per_minute'),
        )->by($request->session()->getId()));
    }
}
