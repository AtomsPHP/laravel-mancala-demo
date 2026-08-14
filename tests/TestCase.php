<?php

declare(strict_types=1);

namespace Tests;

use Atoms\Laravel\AtomsServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AtomsServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->prefix('api')->group(static function (): void {
            require __DIR__ . '/../routes/api.php';
        });
        require __DIR__ . '/../routes/web.php';
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app->usePublicPath(__DIR__ . '/../public');
        $app['config']->set('mancala', require __DIR__ . '/../config/mancala.php');
        $app['config']->set('view.paths', [__DIR__ . '/../resources/views']);
    }
}
