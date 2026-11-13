<?php

namespace FullSmack\EnvironmentGate\Database;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Single source of truth for the token table: its (prefixed) name, its
 * connection, and whether database tokens are active at all.
 */
final class TokenTable
{
    public const DEFAULT_NAME = 'environment_gate_tokens';

    /**
     * Database tokens are only active where the gate itself is enabled.
     * Everywhere else the migration is neither loaded nor run.
     */
    public static function active(?Config $config = null): bool
    {
        $config ??= config();

        return (bool) $config->get('environment-gate.enabled')
            && (bool) $config->get('environment-gate.database.enabled');
    }

    /**
     * The table name including the package's own prefix. A prefix configured
     * on the database connection itself is applied on top by Laravel.
     */
    public static function name(?Config $config = null): string
    {
        $config ??= config();

        return (string) $config->get('environment-gate.database.table_prefix', '')
            .(string) ($config->get('environment-gate.database.table') ?: self::DEFAULT_NAME);
    }

    public static function connection(?Config $config = null): ?string
    {
        $config ??= config();

        return $config->get('environment-gate.database.connection') ?: null;
    }
}
