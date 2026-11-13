<?php

namespace FullSmack\EnvironmentGate\Http\Middleware;

use Closure;
use FullSmack\EnvironmentGate\Contracts\CredentialProvider;
use FullSmack\EnvironmentGate\Credential;
use FullSmack\EnvironmentGate\Credentials;
use FullSmack\EnvironmentGate\CookieSigner;
use FullSmack\EnvironmentGate\Events\EnvironmentUnlocked;
use FullSmack\EnvironmentGate\Events\UnlockFailed;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks every HTTP request behind an access code unless the request carries
 * a valid access cookie, a valid code (link, form or header), comes from an
 * allowlisted IP address or matches an excepted path.
 *
 * Queue workers, the scheduler and Artisan never pass through HTTP
 * middleware, so they keep working while the environment is locked.
 */
class EnvironmentGate
{
    public function __construct(
        protected Application $app,
        protected Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->config->get('environment-gate.enabled'))
        {
            return $next($request);
        }

        $response = $this->process($request, $next);

        if ($this->config->get('environment-gate.noindex', true))
        {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    protected function process(Request $request, Closure $next): Response
    {
        if ($this->isUnlockPath($request))
        {
            return $this->handleUnlockPath($request);
        }

        if ($this->isPreflight($request) || $this->isExcepted($request) || $this->isAllowedIp($request))
        {
            return $next($request);
        }

        $credentials = $this->credentials();

        if ($this->hasValidCookie($request, $credentials))
        {
            return $next($request);
        }

        $header = $this->config->get('environment-gate.header');

        if ($header && $request->headers->has($header))
        {
            return $this->attempt(
                $request,
                $credentials,
                (string) $request->headers->get($header),
                'header',
                onSuccess: fn () => $next($request),
            );
        }

        $parameter = $this->config->get('environment-gate.query_parameter');

        if ($parameter && $request->isMethodSafe() && $request->query->has($parameter))
        {
            $code = $request->query->all()[$parameter];

            return $this->attempt(
                $request,
                $credentials,
                is_string($code) ? $code : '',
                'link',
                onSuccess: fn (Credential $credential) => $this->unlockAndRedirect(
                    $request,
                    $credential,
                    $this->urlWithoutParameter($request, $parameter),
                ),
            );
        }

        return $this->locked($request);
    }

    /**
     * GET shows the unlock form, POST submits it.
     */
    protected function handleUnlockPath(Request $request): Response
    {
        $redirect = $this->safeRedirect($request->input('redirect'));

        if ($request->isMethod('GET') || $request->isMethod('HEAD'))
        {
            return $this->form($request, $redirect, Response::HTTP_OK);
        }

        if (! $request->isMethod('POST'))
        {
            return $this->noStore(new IlluminateResponse('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'GET, HEAD, POST']));
        }

        if (! $this->isSameOrigin($request))
        {
            return $this->form($request, $redirect, Response::HTTP_FORBIDDEN, 'invalid_origin');
        }

        $code = $request->input('code');
        return $this->attempt(
            $request,
            $this->credentials(),
            is_string($code) ? trim($code) : '',
            'form',
            onSuccess: fn (Credential $credential) => $this->unlockAndRedirect($request, $credential, $redirect),
            // The form page is never disguised as a 404, even in stealth mode.
            onFailure: fn (string $error, int $status) => $this->form(
                $request,
                $redirect,
                $status === Response::HTTP_TOO_MANY_REQUESTS ? $status : Response::HTTP_FORBIDDEN,
                $error,
            ),
        );
    }

    /**
     * Throttle, verify a submitted code and continue with the outcome.
     *
     * @param  Closure(Credential): Response  $onSuccess
     * @param  (Closure(string, int): Response)|null  $onFailure
     */
    protected function attempt(Request $request, CredentialProvider $credentials, string $code, string $via, Closure $onSuccess, ?Closure $onFailure = null): Response
    {
        $onFailure ??= fn (string $error, int $status) => $this->locked($request, $error, $status);

        $limiter = $this->limiter();
        $key = 'environment-gate:'.sha1((string) $request->ip());
        $maxAttempts = (int) $this->config->get('environment-gate.throttle.max_attempts', 10);

        if ($limiter && $limiter->tooManyAttempts($key, $maxAttempts))
        {
            return tap($onFailure('throttled', Response::HTTP_TOO_MANY_REQUESTS), function (Response $response) use ($limiter, $key) {
                $response->headers->set('Retry-After', (string) $limiter->availableIn($key));
            });
        }

        $credential = $code === '' ? null : $credentials->match($code);

        if ($credential === null)
        {
            $limiter?->hit($key, (int) $this->config->get('environment-gate.throttle.decay_seconds', 60));
            $this->log('notice', 'Environment gate: invalid code', ['via' => $via, 'ip' => $request->ip()]);
            $this->events()->dispatch(new UnlockFailed($via, $request));

            return $onFailure('invalid', $this->lockedStatus());
        }

        // Header access happens on every machine request, keep it quiet.
        $this->log($via === 'header' ? 'debug' : 'info', 'Environment gate: unlocked', [
            'label' => $credential->label, 'source' => $credential->source, 'via' => $via, 'ip' => $request->ip(),
        ]);
        $this->events()->dispatch(new EnvironmentUnlocked($credential->label, $via, $request, $credential->source));

        return $onSuccess($credential);
    }

    protected function unlockAndRedirect(Request $request, Credential $credential, string $to): Response
    {
        $lifetime = max(1, (int) $this->config->get('environment-gate.cookie.lifetime', 43200));
        $expiresAt = time() + $lifetime * 60;

        // A cookie never outlives the token it was issued for.
        if ($credential->expiresAt !== null)
        {
            $expiresAt = min($expiresAt, $credential->expiresAt);
        }

        $value = $this->signer()->issue($credential, $expiresAt);
        $secure = $this->config->get('environment-gate.cookie.secure');

        $response = new RedirectResponse($to, Response::HTTP_FOUND);
        $response->headers->setCookie(Cookie::create(
            name: $this->cookieName(),
            value: $value,
            expire: $expiresAt,
            path: $this->config->get('environment-gate.cookie.path', '/'),
            domain: $this->config->get('environment-gate.cookie.domain') ?: null,
            secure: $secure === null || $secure === '' ? $request->isSecure() : filter_var($secure, FILTER_VALIDATE_BOOLEAN),
            httpOnly: true,
            raw: false,
            sameSite: $this->config->get('environment-gate.cookie.same_site', Cookie::SAMESITE_LAX),
        ));

        return $this->noStore($response);
    }

    protected function hasValidCookie(Request $request, CredentialProvider $credentials): bool
    {
        $value = $request->cookies->all()[$this->cookieName()] ?? null;

        return is_string($value) && $this->signer()->verify($value, $credentials, time()) !== null;
    }

    protected function locked(Request $request, ?string $error = null, ?int $status = null): Response
    {
        $status ??= $this->lockedStatus();

        if ($request->expectsJson())
        {
            $key = $status === Response::HTTP_NOT_FOUND ? 'not_found_title' : ($error ? "errors.{$error}" : 'locked_message');

            return $this->noStore(new JsonResponse(['message' => __("environment-gate::gate.{$key}")], $status));
        }

        if ($status === Response::HTTP_NOT_FOUND)
        {
            return $this->noStore(new IlluminateResponse(
                view('environment-gate::locked', ['showForm' => false, 'error' => null, 'action' => '', 'redirect' => '/'])->render(),
                $status,
            ));
        }

        return $this->form($request, $this->safeRedirect($request->getRequestUri()), $status, $error);
    }

    protected function form(Request $request, string $redirect, int $status, ?string $error = null): Response
    {
        if ($request->expectsJson())
        {
            $key = $error ? "errors.{$error}" : 'locked_message';

            return $this->noStore(new JsonResponse(['message' => __("environment-gate::gate.{$key}")], $status));
        }

        return $this->noStore(new IlluminateResponse(view('environment-gate::locked', [
            'showForm' => true,
            'error' => $error,
            'action' => $request->getBaseUrl().'/'.$this->unlockPath(),
            'redirect' => $redirect,
        ])->render(), $status));
    }

    protected function lockedStatus(): int
    {
        $status = (int) $this->config->get('environment-gate.status', Response::HTTP_FORBIDDEN);

        return $status >= 400 && $status < 600 ? $status : Response::HTTP_FORBIDDEN;
    }

    protected function isUnlockPath(Request $request): bool
    {
        return $this->unlockPath() !== '' && $request->is($this->unlockPath());
    }

    protected function isPreflight(Request $request): bool
    {
        return $this->config->get('environment-gate.allow_preflight', true)
            && $request->isMethod('OPTIONS')
            && $request->headers->has('Access-Control-Request-Method');
    }

    protected function isExcepted(Request $request): bool
    {
        foreach ((array) $this->config->get('environment-gate.except', []) as $pattern)
        {
            $pattern = trim((string) $pattern);

            if ($pattern === '')
            {
                continue;
            }

            if (str_starts_with($pattern, 'http://') || str_starts_with($pattern, 'https://')
                ? $request->fullUrlIs($pattern)
                : $request->is($pattern === '/' ? '/' : trim($pattern, '/')))
            {
                return true;
            }
        }

        return false;
    }

    protected function isAllowedIp(Request $request): bool
    {
        $ips = $this->config->get('environment-gate.ips', []);
        $ips = array_values(array_filter(array_map('trim', is_array($ips) ? $ips : explode(',', (string) $ips))));
        $ip = $request->ip();

        return $ips !== [] && $ip !== null && IpUtils::checkIp($ip, $ips);
    }

    /**
     * A browser POST from another origin must not be able to unlock (or
     * swap) a visitor's access, as the form carries no CSRF token.
     */
    protected function isSameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        // Non-browser clients (curl, tests) send no Origin at all.
        if ($origin === null || $origin === '')
        {
            return true;
        }

        return strtolower(rtrim($origin, '/')) === strtolower($request->getSchemeAndHttpHost());
    }

    /**
     * Only same-site, path-absolute redirects are allowed.
     */
    protected function safeRedirect(mixed $to): string
    {
        if (! is_string($to) || $to === '' || $to[0] !== '/' || preg_match('#^/[/\\\\]|[\x00-\x1F\x7F\\\\]#', $to))
        {
            return '/';
        }

        return $to;
    }

    protected function urlWithoutParameter(Request $request, string $parameter): string
    {
        $query = Arr::except($request->query->all(), [$parameter]);
        $url = $request->getBaseUrl().$request->getPathInfo();

        return $this->safeRedirect($query === [] ? $url : $url.'?'.Arr::query($query));
    }

    protected function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    protected function unlockPath(): string
    {
        return trim((string) $this->config->get('environment-gate.path', '__access'), '/');
    }

    protected function cookieName(): string
    {
        return (string) $this->config->get('environment-gate.cookie.name', 'environment_gate');
    }

    protected function credentials(): CredentialProvider
    {
        return $this->app->make(Credentials::class);
    }

    protected function signer(): CookieSigner
    {
        return $this->app->make(CookieSigner::class);
    }

    protected function events(): Dispatcher
    {
        return $this->app->make(Dispatcher::class);
    }

    protected function limiter(): ?RateLimiter
    {
        if (! $this->config->get('environment-gate.throttle.enabled', true))
        {
            return null;
        }

        return new RateLimiter(
            $this->app->make(CacheFactory::class)->store($this->config->get('environment-gate.throttle.store')),
        );
    }

    protected function log(string $level, string $message, array $context): void
    {
        if (! $this->config->get('environment-gate.log.enabled', true))
        {
            return;
        }

        $logger = $this->app->make('log');
        $channel = $this->config->get('environment-gate.log.channel');

        /** @var LoggerInterface $logger */
        $logger = $channel && method_exists($logger, 'channel') ? $logger->channel($channel) : $logger;
        $logger->log($level, $message, $context);
    }
}
