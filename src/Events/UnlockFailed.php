<?php

namespace FullSmack\EnvironmentGate\Events;

use Illuminate\Http\Request;

/**
 * A code was submitted that does not match any configured code.
 *
 * $via is "link", "form" or "header".
 */
final class UnlockFailed
{
    public function __construct(
        public readonly string $via,
        public readonly Request $request,
    ) {}
}
