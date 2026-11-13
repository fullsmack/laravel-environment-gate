<?php

namespace FullSmack\EnvironmentGate\Console;

use FullSmack\EnvironmentGate\Codes;
use FullSmack\EnvironmentGate\Console\Concerns\RequiresDatabaseTokens;
use FullSmack\EnvironmentGate\Database\TokenRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class CreateTokenCommand extends Command
{
    use RequiresDatabaseTokens;

    public const MIN_LENGTH = 16;

    protected $signature = 'environment-gate:token:create
                            {label : A label for the person or client receiving the token}
                            {--token= : Use this token instead of generating a random one}
                            {--expires= : Expiry as a number of days, or a date such as 2026-12-31}
                            {--description= : A note about who or what the token is for}
                            {--length=40 : The length of a generated token}
                            {--url= : The URL for the printed link (defaults to APP_URL)}
                            {--force : Replace an existing token with the same label}';

    protected $description = 'Create an access token stored in the database';

    public function handle(TokenRepository $tokens, Codes $codes): int
    {
        if (! $this->databaseTokensEnabled())
        {
            return self::FAILURE;
        }

        $label = (string) $this->argument('label');

        if (! preg_match(Codes::LABEL_PATTERN, $label) || strlen($label) > 100)
        {
            $this->components->error('Labels may only contain letters, digits, "-" and "_" (max 100 characters).');

            return self::FAILURE;
        }

        if ($codes->has($label))
        {
            $this->components->error("[{$label}] is already used by a code in ENVIRONMENT_GATE_CODES. Pick another label.");

            return self::FAILURE;
        }

        if ($tokens->exists($label) && ! $this->option('force'))
        {
            $this->components->error("A token for [{$label}] already exists. Use --force to replace it (this logs out its holders).");

            return self::FAILURE;
        }

        $token = $this->option('token');
        $token = is_string($token) && $token !== '' ? $token : Str::random(max(self::MIN_LENGTH, (int) $this->option('length')));

        if (strlen($token) < self::MIN_LENGTH || preg_match('/[\s,]/', $token))
        {
            $this->components->error('Tokens must be at least '.self::MIN_LENGTH.' characters, without spaces or commas.');

            return self::FAILURE;
        }

        if ($codes->match($token) !== null)
        {
            $this->components->error('That token is already used as a code in ENVIRONMENT_GATE_CODES.');

            return self::FAILURE;
        }

        try {
            $expiresAt = $this->expiresAt();
        }
        catch (Throwable)
        {
            $this->components->error('Could not understand --expires. Use a number of days or a date.');

            return self::FAILURE;
        }

        if ($expiresAt !== null && $expiresAt->isPast())
        {
            $this->components->error('The expiry lies in the past.');

            return self::FAILURE;
        }

        $tokens->store($label, $token, $expiresAt, $this->option('description') ?: null);

        $this->components->info("Token for [{$label}] stored".($expiresAt ? ', expires '.$expiresAt->toDateTimeString() : '').'.');
        // Printed in full (no width-truncating components) so they can be copied.
        $this->line("  Token   {$token}");

        if ($parameter = config('environment-gate.query_parameter'))
        {
            $this->line('  Link    '.LinkCommand::buildLink(
                (string) ($this->option('url') ?: config('app.url')),
                $parameter,
                $token,
            ));
        }

        if ($header = config('environment-gate.header'))
        {
            $this->line("  Header  {$header}: {$token}");
        }

        $this->newLine();
        $this->line('  <comment>Only a hash is stored. This is the only time the token is shown.</comment>');

        return self::SUCCESS;
    }

    protected function expiresAt(): ?Carbon
    {
        $expires = $this->option('expires');

        if ($expires === null || $expires === '')
        {
            return null;
        }

        return ctype_digit((string) $expires)
            ? Carbon::now()->addDays((int) $expires)
            : Carbon::parse((string) $expires);
    }
}
