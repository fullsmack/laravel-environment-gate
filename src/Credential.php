<?php

namespace FullSmack\EnvironmentGate;

/**
 * A resolved access credential.
 *
 * The secret is what access cookies are signed with: the plain code for
 * configured codes, the stored token hash for database tokens. Changing or
 * removing it invalidates every cookie issued for the label.
 */
final class Credential
{
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_DATABASE = 'database';

    public function __construct(
        public readonly string $label,
        public readonly string $secret,
        public readonly string $source,
        public readonly ?int $expiresAt = null,
    ) {}
}
