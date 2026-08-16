<?php

declare(strict_types=1);

namespace App\Providers;

use Atoms\Client\AtomsConfig;
use Atoms\Client\Tickets\TicketClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // atoms/laravel 0.2.0 does not register TicketClient yet. All four of
        // its dependencies are already bound by AtomsServiceProvider, so this
        // is the whole wiring. Remove it when the package ships its own.
        $this->app->singleton(TicketClient::class, static fn (Application $app): TicketClient => new TicketClient(
            $app->make(AtomsConfig::class),
            $app->make(ClientInterface::class),
            $app->make(RequestFactoryInterface::class),
            $app->make(StreamFactoryInterface::class),
        ));
    }

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
