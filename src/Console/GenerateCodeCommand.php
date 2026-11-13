<?php

namespace FullSmack\EnvironmentGate\Console;

use FullSmack\EnvironmentGate\Codes;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateCodeCommand extends Command
{
    protected $signature = 'environment-gate:generate
                            {label : A label for the person or client receiving the code}
                            {--length=24 : The length of the generated code}';

    protected $description = 'Generate a random access code entry for ENVIRONMENT_GATE_CODES';

    public function handle(Codes $codes): int
    {
        $label = (string) $this->argument('label');
        $length = max(12, (int) $this->option('length'));

        if (! preg_match(Codes::LABEL_PATTERN, $label))
        {
            $this->components->error('Labels may only contain letters, digits, "-" and "_".');

            return self::FAILURE;
        }

        if ($codes->has($label))
        {
            $this->components->warn("Label [{$label}] already exists; replace its entry to rotate the code.");
        }

        $this->line("{$label}:".Str::random($length));
        $this->newLine();
        $this->line('<comment>Append it (comma separated) to ENVIRONMENT_GATE_CODES, e.g. with `vapor env:pull` / `vapor env:push`.</comment>');

        return self::SUCCESS;
    }
}
