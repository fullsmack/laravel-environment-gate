<?php

namespace FullSmack\EnvironmentGate\Console;

use FullSmack\EnvironmentGate\Codes;
use Illuminate\Console\Command;

class LinkCommand extends Command
{
    protected $signature = 'environment-gate:link
                            {label : The label of the code to share}
                            {--url= : The URL to link to (defaults to APP_URL)}';

    protected $description = 'Print a shareable link that unlocks the environment gate';

    public function handle(Codes $codes): int
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
            $this->components->error("No code is configured for label [{$label}].");

            if ($codes->labels() !== [])
            {
                $this->line('  Known labels: '.implode(', ', $codes->labels()));
            }

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: config('app.url'));

        if (parse_url($url, PHP_URL_PATH) === null && ! str_contains($url, '?'))
        {
            $url .= '/';
        }

        $this->line($url.(str_contains($url, '?') ? '&' : '?').http_build_query([$parameter => $codes->get($label)]));

        return self::SUCCESS;
    }
}
