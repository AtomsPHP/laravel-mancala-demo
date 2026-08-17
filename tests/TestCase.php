<?php

declare(strict_types=1);

namespace Tests;

use App\Providers\AppServiceProvider;
use Atoms\Laravel\AtomsServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AtomsServiceProvider::class, AppServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        // Testbench builds its own application rather than booting
        // bootstrap/app.php, so the middleware stack is restated here. Keep the
        // two in step: the player's seat key lives in the session, and a route
        // registered without it fails outright. CSRF is skipped automatically
        // under PHPUnit, so the web group is safe to use verbatim.
        $router->middleware('web')->group(static function () use ($router): void {
            $router->prefix('api')->group(static function (): void {
                require __DIR__ . '/../routes/api.php';
            });
            require __DIR__ . '/../routes/web.php';
        });
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app->usePublicPath(__DIR__ . '/../public');
        // AtomsConfig validates the secret on construction, so anything that
        // resolves it — TicketIssuer, and so the ticket route — needs a
        // well-formed one present.
        $app['config']->set('atoms.shared_secret', base64_encode(str_repeat('t', 32)));
        $app['config']->set('mancala', require __DIR__ . '/../config/mancala.php');
        $app['config']->set('view.paths', [__DIR__ . '/../resources/views']);
    }
}
