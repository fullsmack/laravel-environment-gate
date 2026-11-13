# Laravel Environment Gate

Lock staging, demo and preview environments behind a shareable access gate, **without maintenance mode**.

`php artisan down --secret` pauses queue workers and the scheduler. HTTP Basic Auth collides with `Authorization: Bearer` tokens (Sanctum) and is awkward to share. IP allowlists can't be shared by link. This package uses a small global middleware instead:

- Requests without a valid access cookie get a locked page (or a JSON 403 for API clients).
- **Shareable links**: `https://staging.example.com/any/page?access=<code>` sets the cookie and redirects to the same URL without the code.
- **Unlock form**: `/__access`.
- **Machine access**: an `X-Environment-Gate: <code>` header for API clients, Postman, E2E tests and CI smoke tests.
- **Named codes** per person or client, so you can revoke one without logging everyone else out.
- **Database tokens** (optional): issue, list and revoke tokens from the CLI without redeploying.
- Only HTTP requests are gated. Queues, the scheduler and Artisan keep running.

It's built for [Laravel Vapor](https://vapor.laravel.com) but works on any platform (Forge, Cloud, Docker, shared hosting).

Supports Laravel 10, 11, 12 and 13 on PHP 8.1+.

## Installation

```bash
composer require fullsmack/laravel-environment-gate
```

The service provider is auto-discovered, so there's nothing to add to `bootstrap/app.php` or the HTTP kernel.

Then switch the gate on with environment variables in the environments you want locked:

```dotenv
ENVIRONMENT_GATE_ENABLED=true
ENVIRONMENT_GATE_CODES=nicolai:k3x9Qm2LrT8wZp4Y,client-acme:p8q2Vn6HjD1sXc7B
```

Leave `ENVIRONMENT_GATE_ENABLED` unset in production. With the gate disabled, no middleware is registered and no headers are added.

Generate a code:

```bash
php artisan environment-gate:generate client-acme
# client-acme:Xq8f2K...
```

Print a shareable link for a label:

```bash
php artisan environment-gate:link client-acme
# https://staging.example.com/?access=p8q2Vn6HjD1sXc7B

php artisan environment-gate:link client-acme --url=https://staging.example.com/events/42
```

## Database tokens

Env codes need a redeploy to change on Vapor. To issue and revoke access on the fly, store tokens in the database instead, or alongside the env codes.

```dotenv
ENVIRONMENT_GATE_DATABASE=true
```

Database tokens are only active where **both** `ENVIRONMENT_GATE_ENABLED` and `ENVIRONMENT_GATE_DATABASE` are true. Only there does the package load its migration, so `php artisan migrate` (including Vapor's deploy hook) creates the table on staging and demo, and never in production or anywhere else the gate is off.

To keep the migration in your app instead, publish it with `php artisan vendor:publish --tag=environment-gate-migrations`. The published copy has a `shouldRun()` guard, so on Laravel 12+ it's skipped where the package isn't enabled. A skipped migration isn't recorded as run, so it still runs if you enable the package there later. Laravel 10 and 11 don't support `shouldRun()` and would create the empty table everywhere, so prefer the default (unpublished) migration there.

**Table name.** The table is `environment_gate_tokens` by default. Use `ENVIRONMENT_GATE_DB_TABLE_PREFIX` to prefix it, or `ENVIRONMENT_GATE_DB_TABLE` to rename it. A `prefix` set on the database connection is applied on top, as usual in Laravel. For example, a connection prefix `app_` and a table prefix `gate_` give `app_gate_environment_gate_tokens`.

Manage tokens from the CLI:

```bash
php artisan environment-gate:token:create client-acme --expires=30 --description="Acme UAT"
#   Token   Hk2v9...
#   Link    https://staging.example.com/?access=Hk2v9...
#   Header  X-Environment-Gate: Hk2v9...

php artisan environment-gate:token:create ci --token="$CI_GATE_TOKEN"   # set a specific value
php artisan environment-gate:token:create client-acme --force           # replace (rotate) a token
php artisan environment-gate:token:list                                  # labels, expiry, last use; never secrets
php artisan environment-gate:token:revoke client-acme                    # logs its holders out immediately
php artisan environment-gate:token:revoke --expired                      # clean up expired tokens
```

On Vapor, run the same commands with `vapor command staging --command="environment-gate:token:create client-acme --expires=30"`. The output, which includes the token, is visible in the Vapor command log.

How tokens work:

- **Only a SHA-256 hash is stored.** The token is shown once, when you create it, and `environment-gate:link` can't print it later.
- `--expires` takes a number of days or a date. Access cookies never outlive the token they were issued for.
- Labels must be unique across env codes and database tokens. Tokens must be at least 16 characters, without spaces or commas.
- `last_used_at` is updated on link, form and header unlocks, at most every 15 minutes.

Lookups and performance:

- A request without a cookie never queries the database, and neither does a forged cookie. Cookies carry a second signature that only uses the key, which is checked before any lookup.
- Requests with a valid cookie look the token up by label. The result is cached for `ENVIRONMENT_GATE_DB_CACHE_SECONDS` (default 300) in the default cache store, which is DynamoDB on Vapor. Revoking or replacing a token from the CLI clears its cache entry right away.
- If the table is missing or the database is unreachable, the gate fails closed: database tokens are rejected and a warning is logged, but env codes keep working.

## How it works

| Request | Result |
| --- | --- |
| Valid access cookie | Passes |
| `?access=<valid code>` (GET/HEAD) | `302` to the same URL without `access`, sets the cookie |
| `POST /__access` with a valid `code` | `302` to the originally requested URL, sets the cookie |
| `X-Environment-Gate: <valid code>` | Passes (no cookie is set) |
| Excepted path (`up`, webhooks, ...) | Passes |
| Allowlisted IP | Passes |
| CORS preflight (`OPTIONS` + `Access-Control-Request-Method`) | Passes to your CORS middleware |
| Anything else | `403` locked page with unlock form, or JSON `{"message": ...}` |

Every response in a gated environment gets `X-Robots-Tag: noindex, nofollow`, including unlocked and excepted requests. Locked responses and redirects get `Cache-Control: no-store, private`.

### The cookie

The value is `label.expires.envelope.signature`, signed with `APP_KEY` (or `ENVIRONMENT_GATE_KEY`):

- `envelope` is `hmac(label, expires)`, checked first using only the key.
- `signature` is `hmac(label, expires, secret)`. The secret is the code itself, or the stored hash for a database token.

The cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` whenever the request is HTTPS. It lasts 30 days by default, and the expiry is also enforced server-side.

- **Revoke** a person: remove their entry from `ENVIRONMENT_GATE_CODES`. Their cookies stop working, everyone else's keep working.
- **Rotate** a code: change it. Cookies issued for the old code stop working.
- **Log everyone out**: set or change `ENVIRONMENT_GATE_KEY`.

The cookie name is added to the `EncryptCookies` exceptions automatically on Laravel 11+. On Laravel 10, add `environment_gate` to `$except` in `App\Http\Middleware\EncryptCookies`. The gate works without it, but your app would then see the cookie as `null`.

### Placement in the middleware stack

The middleware is added to the **global** stack, right after `TrustProxies` (so `$request->ip()` and the scheme are correct behind load balancers and CloudFront), or first when `TrustProxies` isn't present. It runs before sessions, CSRF, CORS and your routes, so a locked request never starts a session or touches the database.

The unlock form deliberately has no CSRF token, since there's no session. Cross-origin form posts are rejected by checking the `Origin` header, and the redirect target must be a path on the same site.

To control the position yourself, set `register_middleware` to `false` in the published config and register it manually:

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(\FullSmack\EnvironmentGate\Http\Middleware\EnvironmentGate::class);
})
```

## Configuration

Publish the config to change anything beyond the environment variables:

```bash
php artisan vendor:publish --tag=environment-gate-config
```

| Env variable | Default | Purpose |
| --- | --- | --- |
| `ENVIRONMENT_GATE_ENABLED` | `false` | Turn the gate on |
| `ENVIRONMENT_GATE_CODES` | | `label:code,label:code`. Labels may contain `A-Z a-z 0-9 - _` |
| `ENVIRONMENT_GATE_KEY` | `APP_KEY` | Cookie signing key |
| `ENVIRONMENT_GATE_DATABASE` | `false` | Enable database tokens and their migration (requires `ENVIRONMENT_GATE_ENABLED`) |
| `ENVIRONMENT_GATE_DB_CONNECTION` | default connection | Connection for the tokens table |
| `ENVIRONMENT_GATE_DB_TABLE` | `environment_gate_tokens` | Tokens table name |
| `ENVIRONMENT_GATE_DB_TABLE_PREFIX` | | Prefix for the tokens table |
| `ENVIRONMENT_GATE_DB_CACHE_SECONDS` | `300` | Cache for token lookups on cookie requests (`0` disables) |
| `ENVIRONMENT_GATE_DB_CACHE_STORE` | default store | Cache store for token lookups |
| `ENVIRONMENT_GATE_COOKIE` | `environment_gate` | Cookie name |
| `ENVIRONMENT_GATE_COOKIE_DOMAIN` | current host | e.g. `.example.com` to cover `staging.` and `staging-api.` with one unlock |
| `ENVIRONMENT_GATE_COOKIE_LIFETIME` | `43200` | Minutes (30 days) |
| `ENVIRONMENT_GATE_COOKIE_SECURE` | request scheme | Force `true` or `false` |
| `ENVIRONMENT_GATE_PATH` | `__access` | Unlock form path |
| `ENVIRONMENT_GATE_EXCEPT` | | Extra comma-separated paths, added to `up`. e.g. `webhooks/*,stripe/*` |
| `ENVIRONMENT_GATE_IPS` | | Comma-separated IPs or CIDR ranges, e.g. `203.0.113.7,10.0.0.0/8` |
| `ENVIRONMENT_GATE_STATUS` | `403` | `404` hides that a gated environment exists (no form on locked pages; use `/__access`) |
| `ENVIRONMENT_GATE_THROTTLE_STORE` | default cache | Cache store for brute-force throttling |
| `ENVIRONMENT_GATE_LOG_CHANNEL` | default channel | Log channel for unlocks and failed attempts |

Config-only options: `query_parameter` (`access`), `header` (`X-Environment-Gate`), `allow_preflight`, `noindex`, `throttle` (10 failed attempts per IP per 60 seconds), `log.enabled`, `register_middleware`. Set `query_parameter` or `header` to `null` to disable that entry point.

`except` takes the same patterns as `$request->is()`, or full URLs (`https://...`) matched with `$request->fullUrlIs()`.

### Customising the page

```bash
php artisan vendor:publish --tag=environment-gate-views   # resources/views/vendor/environment-gate/locked.blade.php
php artisan vendor:publish --tag=environment-gate-lang    # lang/vendor/environment-gate/{en,da}/gate.php
```

English and Danish translations are included and follow the app locale.

### Events and logging

- `FullSmack\EnvironmentGate\Events\EnvironmentUnlocked` (`label`, `via`: `link` | `form` | `header`, `request`, `source`: `config` | `database`)
- `FullSmack\EnvironmentGate\Events\UnlockFailed` (`via`, `request`)

Unlocks are logged at `info` with the label and IP, never the code. Header unlocks are logged at `debug` because they happen on every machine request. Failed attempts are logged at `notice`.

## Laravel Vapor

**Queues and the scheduler keep running.** Vapor runs queue jobs and `schedule:run` in separate Lambda invocations that never go through the HTTP kernel, so this middleware never sees them. That's the main reason to use this package instead of `vapor down`. Keep `vapor down` and maintenance mode for real maintenance.

**Setting it up per environment:**

```bash
vapor env:pull staging
# add ENVIRONMENT_GATE_ENABLED=true and ENVIRONMENT_GATE_CODES=... to .env.staging
vapor env:push staging
vapor deploy staging
```

Repeat for `demo` and any other environment you want locked. Production stays untouched.

**Separate API subdomain.** If the frontend lives on `staging.example.com` and the API on `staging-api.example.com`, set `ENVIRONMENT_GATE_COOKIE_DOMAIN=.example.com` so one unlock covers both. Requests with credentials (`fetch(..., {credentials: 'include'})`, `axios` `withCredentials`) then send the cookie. CORS preflights pass without a cookie by design.

Locked API responses short-circuit before `HandleCors`, so a browser sees a CORS error instead of the 403. Unlock the frontend first, or use the header.

**API clients, Postman and CI:**

```bash
curl -H "X-Environment-Gate: $STAGING_CODE" https://staging-api.example.com/api/health
```

The header doesn't touch `Authorization`, so Sanctum bearer tokens keep working.

**Webhooks.** Payment and mail providers can't unlock, so except their routes: `ENVIRONMENT_GATE_EXCEPT=webhooks/*,stripe/webhook`.

**Signed storage URLs.** `vapor/signed-storage-url` is called from the browser of an unlocked user, so the cookie is sent and it works through the gate. Except it only if you call it from somewhere that can't carry the cookie.

**Throttling** uses the default cache store (DynamoDB on Vapor), and only on requests that actually submit a code.

**IP allowlist** relies on `$request->ip()`. Make sure `TrustProxies` trusts Vapor's proxies (`$middleware->trustProxies(at: '*')` on Laravel 11+).

## Testing your own app

The gate is disabled unless `ENVIRONMENT_GATE_ENABLED=true`, so your test suite isn't affected. To run E2E tests against a gated environment, send the header or open a link with `?access=` first.

## Development

```bash
composer test       # PHPUnit (Testbench)
composer lint       # php-cs-fixer dry run (fullsmack/php-cs-fixer-breathe)
composer lint:fix   # apply fixes
```

## License

MIT. See [LICENSE](LICENSE).
