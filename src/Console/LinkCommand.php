<?php

namespace FullSmack\EnvironmentGate\Console;

use FullSmack\EnvironmentGate\Codes;
use FullSmack\EnvironmentGate\Database\TokenRepository;
use FullSmack\EnvironmentGate\Database\TokenTable;
use Illuminate\Console\Command;

class LinkCommand extends Command
{
    protected $signature = 'environment-gate:link
                            {label : The label of the code to share}
                            {--url= : The URL to link to (defaults to APP_URL)}';

    protected $description = 'Print a shareable link that unlocks the environment gate';

    public function handle(Codes $codes, TokenRepository $tokens): int
    {
        $label = (string) $this->argument('label');
        $parameter = config('environment-gate.query_parameter');

        if (! $parameter)
        {
            $this->components->error('Shareable links are disabled (environment-gate.query_parameter is empty).');

            return self::FAILURE;
        }

        if (! $codes->has($label))
        {
            if (TokenTable::active() && rescue(fn () => $tokens->exists($label), false, false))
            {
                $this->components->error(
                    "[{$label}] is a database token. Only its hash is stored, so the link is shown once, when the token is created. "
                    .'Run environment-gate:token:create again to replace it.',
                );

                return self::FAILURE;
            }

            $this->components->error("No code is configured for label [{$label}].");

            if ($codes->labels() !== [])
            {
                $this->line('  Known labels: '.implode(', ', $codes->labels()));
            }

            return self::FAILURE;
        }

        $this->line(static::buildLink(
            (string) ($this->option('url') ?: config('app.url')),
            $parameter,
            (string) $codes->get($label),
        ));

        return self::SUCCESS;
    }

    public static function buildLink(string $url, string $parameter, string $code): string
    {
        if (parse_url($url, PHP_URL_PATH) === null && ! str_contains($url, '?'))
        {
            $url .= '/';
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([$parameter => $code]);
    }
}
