<?php

namespace FullSmack\EnvironmentGate\Tests\Feature;

use FullSmack\EnvironmentGate\Http\Middleware\EnvironmentGate;
use FullSmack\EnvironmentGate\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;

class DisabledGateTest extends TestCase
{
    protected bool $gateEnabled = false;

    public function test_middleware_is_not_registered(): void
    {
        $this->assertFalse($this->app->make(Kernel::class)->hasMiddleware(EnvironmentGate::class));
    }

    public function test_token_commands_require_database_tokens(): void
    {
        $this->artisan('environment-gate:token:create', ['label' => 'x'])
            ->expectsOutputToContain('ENVIRONMENT_GATE_DATABASE=true')
            ->assertFailed();
        $this->artisan('environment-gate:token:revoke', ['label' => 'x'])->assertFailed();
    }

    public function test_migration_is_not_loaded_without_database_tokens(): void
    {
        $this->assertNotContains(
            realpath(__DIR__.'/../../database/migrations'),
            array_map('realpath', $this->app->make('migrator')->paths()),
        );
    }

    public function test_everything_is_open_and_untouched(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('dashboard')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/__access')->assertNotFound();
        $this->get('/dashboard?access=wrong')->assertOk();
    }
}
