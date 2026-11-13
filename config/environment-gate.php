<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable the gate
    |--------------------------------------------------------------------------
    |
    | When false (the default) the package does nothing: no middleware is
    | registered and no headers are added. Switch it on per environment, e.g.
    | in the Vapor environment variables for staging and demo only.
    |
    */

    'enabled' => (bool) env('ENVIRONMENT_GATE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Access codes
    |--------------------------------------------------------------------------
    |
    | Named codes in the form "label:code,label:code". Give each person or
    | client their own code so one can be revoked without logging everyone
    | else out. Labels may contain letters, digits, "-" and "_". The label
    | (never the code) is logged and dispatched with events on unlock.
    |
    | An array of label => code pairs is accepted as well.
    |
    */

    'codes' => env('ENVIRONMENT_GATE_CODES', ''),

    /*
    |--------------------------------------------------------------------------
    | Signing key
    |--------------------------------------------------------------------------
    |
    | The key used to sign the access cookie. Defaults to APP_KEY. Changing it
    | invalidates every issued cookie at once.
    |
    */

    'key' => env('ENVIRONMENT_GATE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Access cookie
    |--------------------------------------------------------------------------
    |
    | "domain" can be set to e.g. ".example.com" so that a single unlock covers
    | staging.example.com and staging-api.example.com. "secure" defaults to
    | the scheme of the current request when left null. "lifetime" is in
    | minutes (default 30 days) and is enforced server-side as well.
    |
    */

    'cookie' => [
        'name' => env('ENVIRONMENT_GATE_COOKIE', 'environment_gate'),
        'domain' => env('ENVIRONMENT_GATE_COOKIE_DOMAIN'),
        'path' => '/',
        'lifetime' => (int) env('ENVIRONMENT_GATE_COOKIE_LIFETIME', 60 * 24 * 30),
        'secure' => env('ENVIRONMENT_GATE_COOKIE_SECURE'),
        'same_site' => 'lax',
    ],

    /*
    |--------------------------------------------------------------------------
    | Unlock entry points
    |--------------------------------------------------------------------------
    |
    | "path"            The unlock form (GET shows it, POST submits it).
    | "query_parameter" Shareable links: /any/page?access=<code>. The code is
    |                   stripped from the URL with a redirect. Null disables.
    | "header"          Machine access (API clients, CI, E2E tests). The
    |                   header value is the code. Null disables.
    |
    */

    'path' => env('ENVIRONMENT_GATE_PATH', '__access'),

    'query_parameter' => 'access',

    'header' => 'X-Environment-Gate',

    /*
    |--------------------------------------------------------------------------
    | Always-open paths
    |--------------------------------------------------------------------------
    |
    | Request patterns (as for $request->is()) that bypass the gate, such as
    | health checks and inbound webhooks from payment or mail providers.
    | Additional patterns can be added via ENVIRONMENT_GATE_EXCEPT as a
    | comma separated list.
    |
    */

    'except' => array_merge(
        ['up'],
        array_filter(array_map('trim', explode(',', (string) env('ENVIRONMENT_GATE_EXCEPT', '')))),
    ),

    /*
    |--------------------------------------------------------------------------
    | IP allowlist
    |--------------------------------------------------------------------------
    |
    | Comma separated IPv4/IPv6 addresses or CIDR ranges (office, VPN) that
    | pass without a code. Make sure your TrustProxies configuration is
    | correct, as the gate relies on $request->ip().
    |
    */

    'ips' => env('ENVIRONMENT_GATE_IPS', ''),

    /*
    |--------------------------------------------------------------------------
    | CORS preflight
    |--------------------------------------------------------------------------
    |
    | Browsers never send cookies with CORS preflight (OPTIONS) requests, so
    | they are let through to your CORS middleware. The real request that
    | follows is still gated.
    |
    */

    'allow_preflight' => true,

    /*
    |--------------------------------------------------------------------------
    | Locked response
    |--------------------------------------------------------------------------
    |
    | 403 shows a friendly page with an unlock form. 404 shows a plain
    | "Not Found" page instead, hiding that a gated environment exists; the
    | form is then only reachable on the unlock path above.
    |
    */

    'status' => (int) env('ENVIRONMENT_GATE_STATUS', 403),

    /*
    |--------------------------------------------------------------------------
    | Search engines
    |--------------------------------------------------------------------------
    |
    | Adds "X-Robots-Tag: noindex, nofollow" to every response while the gate
    | is enabled, including unlocked and excepted requests.
    |
    */

    'noindex' => true,

    /*
    |--------------------------------------------------------------------------
    | Brute force protection
    |--------------------------------------------------------------------------
    |
    | Failed code attempts per IP address, counted with the cache. Uses the
    | default cache store (DynamoDB on Vapor) unless "store" is set.
    |
    */

    'throttle' => [
        'enabled' => true,
        'max_attempts' => 10,
        'decay_seconds' => 60,
        'store' => env('ENVIRONMENT_GATE_THROTTLE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Log unlocks (label, method, IP) and failed attempts. Null uses the
    | default log channel.
    |
    */

    'log' => [
        'enabled' => true,
        'channel' => env('ENVIRONMENT_GATE_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware registration
    |--------------------------------------------------------------------------
    |
    | When true the middleware is added to the global HTTP middleware stack
    | automatically (only while the gate is enabled), right after
    | TrustProxies, or first when TrustProxies is absent. Set it to false to
    | register \FullSmack\EnvironmentGate\Http\Middleware\EnvironmentGate
    | yourself.
    |
    */

    'register_middleware' => true,

];
