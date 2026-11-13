<?php

namespace FullSmack\EnvironmentGate\Contracts;

use FullSmack\EnvironmentGate\Credential;

interface CredentialProvider
{
    /**
     * Resolve the credential for a submitted code or token.
     */
    public function match(string $input): ?Credential;

    /**
     * Resolve a currently valid credential by its label.
     */
    public function find(string $label): ?Credential;
}
