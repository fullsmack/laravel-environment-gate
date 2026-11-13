<?php

namespace FullSmack\EnvironmentGate\Events;

use FullSmack\EnvironmentGate\Credential;
use Illuminate\Http\Request;

/**
 * A request was let through by a valid code or token.
 *
 * $via is "link", "form" or "header". $source is "config" for codes from
 * ENVIRONMENT_GATE_CODES and "database" for database tokens.
 * Cookie-authenticated requests do not dispatch this event.
 */
final class EnvironmentUnlocked
{
    public function __construct(
        public readonly string $label,
        public readonly string $via,
        public readonly Request $request,
        public readonly string $source = Credential::SOURCE_CONFIG,
    ) {}
}
