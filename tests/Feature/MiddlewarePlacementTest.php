<?php

namespace FullSmack\EnvironmentGate\Tests\Feature;

use FullSmack\EnvironmentGate\Http\Middleware\EnvironmentGate;
use FullSmack\EnvironmentGate\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustProxies;

class MiddlewarePlacementTest extends TestCase
{
    public function test_gate_is_placed_right_after_trust_proxies(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $middleware = array_values((fn () => $this->middleware)->call($kernel));

        $gate = array_search(EnvironmentGate::class, $middleware, true);
        $proxies = array_search(TrustProxies::class, $middleware, true);

        $this->assertNotFalse($gate);
        $this->assertSame(1, array_count_values($middleware)[EnvironmentGate::class]);

        if ($proxies !== false)
        {
            $this->assertSame($proxies + 1, $gate);
        }
        else
        {
            $this->assertSame(0, $gate);
        }

        $cors = array_search(HandleCors::class, $middleware, true);

        if ($cors !== false)
        {
            $this->assertLessThan($cors, $gate, 'The gate must run before CORS handling.');
        }
    }
}
