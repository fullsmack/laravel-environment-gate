<?php

namespace FullSmack\EnvironmentGate;

use FullSmack\EnvironmentGate\Contracts\CredentialProvider;
use RuntimeException;

/**
 * Issues and verifies access cookie values of the form
 * "<label>.<expires>.<envelope>.<signature>".
 *
 * - envelope:  hmac(key, label, expires). Verified first using only the key,
 *              so forged or garbage cookies never cause a credential lookup
 *              (which may hit the database).
 * - signature: hmac(key, label, expires, secret). Because the credential's
 *              secret is part of it, rotating or revoking a code or token
 *              invalidates every cookie issued for it.
 */
final class CookieSigner
{
    public function __construct(private readonly string $key)
    {
        if ($key === '')
        {
            throw new RuntimeException(
                'The environment gate needs a signing key. Set APP_KEY or ENVIRONMENT_GATE_KEY.',
            );
        }
    }

    public function issue(Credential $credential, int $expiresAt): string
    {
        return implode('.', [
            $credential->label,
            $expiresAt,
            $this->envelope($credential->label, $expiresAt),
            $this->signature($credential->label, $expiresAt, $credential->secret),
        ]);
    }

    /**
     * Return the credential behind a valid, unexpired cookie value, or null.
     */
    public function verify(string $value, CredentialProvider $credentials, int $now): ?Credential
    {
        $parts = explode('.', $value);

        if (count($parts) !== 4)
        {
            return null;
        }

        [$label, $expiresAt, $envelope, $signature] = $parts;

        if (! ctype_digit($expiresAt) || (int) $expiresAt <= $now)
        {
            return null;
        }

        if (! hash_equals($this->envelope($label, (int) $expiresAt), $envelope))
        {
            return null;
        }

        $credential = $credentials->find($label);

        if ($credential === null || ($credential->expiresAt !== null && $credential->expiresAt <= $now))
        {
            return null;
        }

        return hash_equals($this->signature($label, (int) $expiresAt, $credential->secret), $signature)
            ? $credential
            : null;
    }

    private function envelope(string $label, int $expiresAt): string
    {
        return hash_hmac('sha256', "environment-gate:envelope\n{$label}\n{$expiresAt}", $this->key);
    }

    private function signature(string $label, int $expiresAt, string $secret): string
    {
        return hash_hmac('sha256', "environment-gate:signature\n{$label}\n{$expiresAt}\n{$secret}", $this->key);
    }
}
