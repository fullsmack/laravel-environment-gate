<?php

namespace FullSmack\EnvironmentGate\Tests;

use FullSmack\EnvironmentGate\EnvironmentGateServiceProvider;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected bool $gateEnabled = true;

    protected bool $databaseTokens = false;

    protected function getPackageProviders($app): array
    {
        return [EnvironmentGateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('environment-gate.enabled', $this->gateEnabled);
        $app['config']->set('environment-gate.codes', 'alice:alice-secret-code,client-acme:acme-secret-code');
        $app['config']->set('environment-gate.except', ['up', 'webhooks/*']);
        $app['config']->set('environment-gate.database.enabled', $this->databaseTokens);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/', fn () => 'home');
        Route::get('/dashboard', fn () => 'dashboard');
        Route::get('/up', fn () => 'healthy');
        Route::post('/webhooks/stripe', fn () => 'webhook received');
        Route::get('/api/me', fn () => ['name' => 'alice']);
        Route::middleware('web')->get('/web', fn () => 'web page');
    }
}
