<?php

namespace FullSmack\EnvironmentGate;

use FullSmack\EnvironmentGate\Console\GenerateCodeCommand;
use FullSmack\EnvironmentGate\Console\LinkCommand;
use FullSmack\EnvironmentGate\Http\Middleware\EnvironmentGate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;
use Closure;

class EnvironmentGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/environment-gate.php', 'environment-gate');

        // Bound (not shared) so config changes, e.g. under Octane or in tests,
        // are always picked up. Parsing a handful of codes is cheap.
        $this->app->bind(Codes::class, fn ($app) => Codes::parse($app['config']->get('environment-gate.codes')));

        $this->app->bind(CookieSigner::class, fn ($app) => new CookieSigner(
            (string) ($app['config']->get('environment-gate.key') ?: $app['config']->get('app.key')),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'environment-gate');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'environment-gate');

        if ($this->app->runningInConsole())
        {
            $this->publishes([
                __DIR__.'/../config/environment-gate.php' => $this->app->configPath('environment-gate.php'),
            ], 'environment-gate-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/environment-gate'),
            ], 'environment-gate-views');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/environment-gate'),
            ], 'environment-gate-lang');

            $this->commands([
                LinkCommand::class,
                GenerateCodeCommand::class,
            ]);
        }

        $config = $this->app->make('config');

        if ($config->get('environment-gate.enabled') && $config->get('environment-gate.register_middleware', true))
        {
            $this->registerMiddleware();
        }
    }

    protected function registerMiddleware(): void
    {
        $cookie = (string) $this->app->make('config')->get('environment-gate.cookie.name', 'environment_gate');

        // The gate reads and writes its cookie raw; EncryptCookies must leave it alone.
        if (method_exists(EncryptCookies::class, 'except'))
        {
            EncryptCookies::except($cookie);
        }

        // Runs immediately if the kernel was already resolved (normal HTTP
        // lifecycle), otherwise as soon as it is.
        $this->callAfterResolving(HttpKernel::class, function ($kernel) {
            if (! $kernel instanceof \Illuminate\Foundation\Http\Kernel || $kernel->hasMiddleware(EnvironmentGate::class))
            {
                return;
            }

            static::insertIntoGlobalStack($kernel);
        });
    }

    /**
     * Place the gate right after TrustProxies, so $request->ip() and the
     * scheme are correct behind load balancers, but before sessions, CSRF,
     * CORS and anything else that might touch the database. Falls back to
     * the very first position.
     */
    public static function insertIntoGlobalStack(\Illuminate\Foundation\Http\Kernel $kernel): void
    {
        // Laravel 10 has no public getter/setter for the global stack, so the
        // protected property is accessed directly; this works on every version.
        $access = Closure::bind(
            fn (?array $set = null) => $set === null ? $this->middleware : $this->middleware = $set,
            $kernel,
            \Illuminate\Foundation\Http\Kernel::class,
        );

        $middleware = array_values($access());
        $position = 0;

        foreach ($middleware as $index => $class)
        {
            $name = is_string($class) ? explode(':', $class, 2)[0] : null;

            if ($name && class_exists($name) && is_a($name, TrustProxies::class, true))
            {
                $position = $index + 1;
            }
        }

        array_splice($middleware, $position, 0, [EnvironmentGate::class]);

        $access($middleware);
    }
}
