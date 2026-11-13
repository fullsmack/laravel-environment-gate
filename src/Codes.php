<?php

namespace FullSmack\EnvironmentGate;

use FullSmack\EnvironmentGate\Contracts\CredentialProvider;
use InvalidArgumentException;

/**
 * The configured access codes, keyed by label.
 */
final class Codes implements CredentialProvider
{
    public const LABEL_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * @param  array<string, string>  $codes
     */
    public function __construct(private readonly array $codes)
    {
        foreach ($codes as $label => $code)
        {
            if (! is_string($label) || ! preg_match(self::LABEL_PATTERN, $label))
            {
                throw new InvalidArgumentException(sprintf(
                    'Environment gate label [%s] is invalid. Use letters, digits, "-" and "_" only.',
                    (string) $label,
                ));
            }

            if (! is_string($code) || $code === '')
            {
                throw new InvalidArgumentException("Environment gate code for label [{$label}] is empty.");
            }
        }
    }

    /**
     * Parse "label:code,label:code" or an array of label => code pairs.
     * Entries without a label are named "code-1", "code-2", ...
     *
     * @param  string|array<int|string, string>|null  $value
     */
    public static function parse(string|array|null $value): self
    {
        if ($value === null || $value === '' || $value === [])
        {
            return new self([]);
        }

        $entries = is_array($value) ? $value : explode(',', $value);
        $codes = [];
        $position = 0;

        foreach ($entries as $key => $entry)
        {
            $position++;

            if (is_string($key))
            {
                [$label, $code] = [trim($key), trim((string) $entry)];
            }
            else
            {
                $entry = trim((string) $entry);

                if ($entry === '')
                {
                    continue;
                }

                if (str_contains($entry, ':'))
                {
                    [$label, $code] = array_map('trim', explode(':', $entry, 2));
                }
                else
                {
                    [$label, $code] = ["code-{$position}", $entry];
                }
            }

            if (array_key_exists($label, $codes))
            {
                throw new InvalidArgumentException("Environment gate label [{$label}] is defined more than once.");
            }

            $codes[$label] = $code;
        }

        return new self($codes);
    }

    /**
     * Find the code matching the given input, comparing in constant time
     * against every configured code.
     */
    public function match(string $input): ?Credential
    {
        $found = null;

        foreach ($this->codes as $label => $code)
        {
            if (hash_equals($code, $input) && $found === null)
            {
                $found = $label;
            }
        }

        return $found === null ? null : $this->find($found);
    }

    public function find(string $label): ?Credential
    {
        return isset($this->codes[$label])
            ? new Credential($label, $this->codes[$label], Credential::SOURCE_CONFIG)
            : null;
    }

    public function get(string $label): ?string
    {
        return $this->codes[$label] ?? null;
    }

    public function has(string $label): bool
    {
        return isset($this->codes[$label]);
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return array_keys($this->codes);
    }

    public function isEmpty(): bool
    {
        return $this->codes === [];
    }
}
