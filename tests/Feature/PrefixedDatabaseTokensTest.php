<?php

namespace FullSmack\EnvironmentGate\Tests\Feature;

use FullSmack\EnvironmentGate\Database\TokenTable;
use Illuminate\Support\Facades\DB;

/**
 * Runs every database token test again with a package table prefix and a
 * connection-level prefix stacked on top of each other.
 */
class PrefixedDatabaseTokensTest extends DatabaseTokensTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('environment-gate.database.table_prefix', 'gate_');
        $app['config']->set('database.connections.testing.prefix', 'app_');
    }

    public function test_table_name_combines_both_prefixes(): void
    {
        $this->assertSame('gate_environment_gate_tokens', TokenTable::name());

        $tables = collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name')->all();

        $this->assertContains('app_gate_environment_gate_tokens', $tables);
        $this->assertNotContains('environment_gate_tokens', $tables);
        $this->assertNotContains('gate_environment_gate_tokens', $tables);
    }

    public function test_custom_table_name(): void
    {
        config(['environment-gate.database.table' => 'access_tokens']);

        $this->assertSame('gate_access_tokens', TokenTable::name());
    }
}
