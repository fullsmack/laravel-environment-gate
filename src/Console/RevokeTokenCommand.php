<?php

namespace FullSmack\EnvironmentGate\Console;

use FullSmack\EnvironmentGate\Codes;
use FullSmack\EnvironmentGate\Console\Concerns\RequiresDatabaseTokens;
use FullSmack\EnvironmentGate\Database\TokenRepository;
use Illuminate\Console\Command;

class RevokeTokenCommand extends Command
{
    use RequiresDatabaseTokens;

    protected $signature = 'environment-gate:token:revoke
                            {label? : The label of the token to revoke}
                            {--expired : Remove all expired tokens instead}';

    protected $description = 'Revoke a database access token, logging out everyone who unlocked with it';

    public function handle(TokenRepository $tokens, Codes $codes): int
    {
        if (! $this->databaseTokensEnabled())
        {
            return self::FAILURE;
        }

        if ($this->option('expired'))
        {
            $labels = $tokens->purgeExpired();

            $this->components->info($labels === []
                ? 'No expired tokens.'
                : 'Removed expired tokens: '.implode(', ', $labels).'.');

            return self::SUCCESS;
        }

        $label = (string) $this->argument('label');

        if ($label === '')
        {
            $this->components->error('Pass a label, or --expired.');

            return self::FAILURE;
        }

        if ($tokens->revoke($label))
        {
            $this->components->info("Token [{$label}] revoked.");

            return self::SUCCESS;
        }

        $this->components->error($codes->has($label)
            ? "[{$label}] is a code in ENVIRONMENT_GATE_CODES. Remove it from the environment to revoke it."
            : "No token found for [{$label}].");

        return self::FAILURE;
    }
}
