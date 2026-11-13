<?php

namespace FullSmack\EnvironmentGate\Console\Concerns;

use FullSmack\EnvironmentGate\Database\TokenTable;

trait RequiresDatabaseTokens
{
    protected function databaseTokensEnabled(): bool
    {
        if (TokenTable::active())
        {
            return true;
        }

        $this->components->error(
            'Database tokens are not active in this environment. '
            .'Set ENVIRONMENT_GATE_ENABLED=true and ENVIRONMENT_GATE_DATABASE=true, then run the migrations.',
        );

        return false;
    }
}
