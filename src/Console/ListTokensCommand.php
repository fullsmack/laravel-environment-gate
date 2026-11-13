<?php

namespace FullSmack\EnvironmentGate\Console;

use FullSmack\EnvironmentGate\Codes;
use FullSmack\EnvironmentGate\Database\TokenRepository;
use FullSmack\EnvironmentGate\Database\TokenTable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ListTokensCommand extends Command
{
    protected $signature = 'environment-gate:token:list';

    protected $description = 'List environment gate access tokens and configured code labels';

    public function handle(TokenRepository $tokens, Codes $codes): int
    {
        $rows = [];

        foreach ($codes->labels() as $label)
        {
            $rows[] = [$label, 'config', '', '-', '-', '-'];
        }

        if (TokenTable::active())
        {
            foreach ($tokens->all() as $token)
            {
                $expired = $token->expires_at !== null && Carbon::parse($token->expires_at)->isPast();

                $rows[] = [
                    $token->label,
                    'database',
                    (string) $token->description,
                    ($token->expires_at ?? 'never').($expired ? ' (expired)' : ''),
                    $token->last_used_at ?? 'never',
                    (string) $token->created_at,
                ];
            }
        }

        if ($rows === [])
        {
            $this->components->info('No codes or tokens are configured.');

            return self::SUCCESS;
        }

        $this->table(['Label', 'Source', 'Description', 'Expires', 'Last used', 'Created'], $rows);

        return self::SUCCESS;
    }
}
