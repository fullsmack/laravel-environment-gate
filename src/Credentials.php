<?php

namespace FullSmack\EnvironmentGate;

use FullSmack\EnvironmentGate\Contracts\CredentialProvider;

/**
 * Resolves credentials from several providers, first match wins.
 * Configured codes come first, then database tokens (when enabled).
 */
final class Credentials implements CredentialProvider
{
    /**
     * @param  list<CredentialProvider>  $providers
     */
    public function __construct(private readonly array $providers) {}

    public function match(string $input): ?Credential
    {
        if ($input === '')
        {
            return null;
        }

        foreach ($this->providers as $provider)
        {
            if ($credential = $provider->match($input))
            {
                return $credential;
            }
        }

        return null;
    }

    public function find(string $label): ?Credential
    {
        foreach ($this->providers as $provider)
        {
            if ($credential = $provider->find($label))
            {
                return $credential;
            }
        }

        return null;
    }
}
