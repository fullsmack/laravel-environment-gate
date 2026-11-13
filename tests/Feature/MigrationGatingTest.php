<?php

namespace FullSmack\EnvironmentGate\Tests\Feature;

use FullSmack\EnvironmentGate\Database\TokenTable;
use FullSmack\EnvironmentGate\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Database tokens are switched on, but the gate itself is not (e.g. a stray
 * ENVIRONMENT_GATE_DATABASE=true in production): nothing may be migrated.
 */
class MigrationGatingTest extends TestCase
{
    protected bool $gateEnabled = false;

    protected bool $databaseTokens = true;

    private const MIGRATION = __DIR__.'/../../database/migrations/2026_11_13_000000_create_environment_gate_tokens_table.php';

    public function test_tokens_are_inactive_without_the_gate(): void
    {
        $this->assertFalse(TokenTable::active());

        config(['environment-gate.enabled' => true]);
        $this->assertTrue(TokenTable::active());

        config(['environment-gate.database.enabled' => false]);
        $this->assertFalse(TokenTable::active());
    }

    public function test_migration_is_not_loaded(): void
    {
        $this->assertNotContains(
            realpath(dirname(self::MIGRATION)),
            array_map('realpath', $this->app->make('migrator')->paths()),
        );

        $this->artisan('migrate')->assertSuccessful();

        $this->assertFalse(Schema::hasTable(TokenTable::name()));
    }

    public function test_published_migration_is_skipped_without_being_recorded(): void
    {
        if (! method_exists(Migration::class, 'shouldRun'))
        {
            $this->markTestSkipped('This Laravel version does not support Migration::shouldRun().');
        }

        $this->assertFalse((require self::MIGRATION)->shouldRun());

        // Simulate a copy published into the app's migrations folder.
        $this->artisan('migrate', ['--path' => self::MIGRATION, '--realpath' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable(TokenTable::name()));
        $this->assertNotContains(
            '2026_11_13_000000_create_environment_gate_tokens_table',
            $this->app->make('migration.repository')->getRan(),
        );

        // Enabling the gate later makes the next migrate create the table.
        config(['environment-gate.enabled' => true]);

        $this->artisan('migrate', ['--path' => self::MIGRATION, '--realpath' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable(TokenTable::name()));
    }

    public function test_token_commands_refuse_to_run(): void
    {
        $this->artisan('environment-gate:token:create', ['label' => 'x'])
            ->expectsOutputToContain('ENVIRONMENT_GATE_ENABLED=true')
            ->assertFailed();
    }
}
