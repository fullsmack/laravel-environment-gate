<?php

namespace FullSmack\EnvironmentGate;

use RuntimeException;

/**
 * Issues and verifies access cookie values of the form
 * "<label>.<expires>.<hmac(label, expires, code)>".
 *
 * Because the code itself is part of the signed payload, rotating or
 * removing a code invalidates every cookie issued for it.
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

    public function issue(string $label, string $code, int $expiresAt): string
    {
        return $label.'.'.$expiresAt.'.'.$this->signature($label, $code, $expiresAt);
    }

    /**
     * Return the label of a valid, unexpired cookie value, or null.
     */
    public function verify(string $value, Codes $codes, int $now): ?string
    {
        $parts = explode('.', $value);

        if (count($parts) !== 3)
        {
            return null;
        }

        [$label, $expiresAt, $signature] = $parts;

        if (! ctype_digit($expiresAt) || (int) $expiresAt <= $now)
        {
            return null;
        }

        $code = $codes->get($label);

        if ($code === null)
        {
            return null;
        }

        return hash_equals($this->signature($label, $code, (int) $expiresAt), $signature)
            ? $label
            : null;
    }

    private function signature(string $label, string $code, int $expiresAt): string
    {
        return hash_hmac('sha256', "environment-gate\n{$label}\n{$expiresAt}\n{$code}", $this->key);
    }
}
