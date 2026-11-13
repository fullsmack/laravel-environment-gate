<?php

namespace FullSmack\EnvironmentGate\Events;

use Illuminate\Http\Request;

/**
 * A request was let through by a valid code.
 *
 * $via is "link", "form" or "header". Cookie-authenticated requests do not
 * dispatch this event.
 */
final class EnvironmentUnlocked
{
    public function __construct(
        public readonly string $label,
        public readonly string $via,
        public readonly Request $request,
    ) {}
}
