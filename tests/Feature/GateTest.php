<?php

namespace FullSmack\EnvironmentGate\Tests\Feature;

use FullSmack\EnvironmentGate\Codes;
use FullSmack\EnvironmentGate\CookieSigner;
use FullSmack\EnvironmentGate\Events\EnvironmentUnlocked;
use FullSmack\EnvironmentGate\Events\UnlockFailed;
use FullSmack\EnvironmentGate\Http\Middleware\EnvironmentGate;
use FullSmack\EnvironmentGate\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

class GateTest extends TestCase
{
    private const COOKIE = 'environment_gate';

    private function validCookie(string $label = 'alice', ?int $expiresAt = null): string
    {
        $codes = $this->app->make(Codes::class);

        return $this->app->make(CookieSigner::class)
            ->issue($label, (string) $codes->get($label), $expiresAt ?? time() + 3600);
    }

    public function test_middleware_is_registered_globally_when_enabled(): void
    {
        $this->assertTrue($this->app->make(Kernel::class)->hasMiddleware(EnvironmentGate::class));
    }

    public function test_requests_are_locked_by_default(): void
    {
        $this->get('/dashboard')
            ->assertForbidden()
            ->assertSee('Private environment')
            ->assertSee('name="code"', false)
            ->assertSee('value="/dashboard"', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('dashboard</', false);
    }

    public function test_locked_json_requests_get_json(): void
    {
        $this->getJson('/api/me')
            ->assertForbidden()
            ->assertJson(['message' => 'This environment is private. Enter your access code to continue.']);
    }

    public function test_locked_requests_do_not_start_a_session(): void
    {
        $this->get('/web')->assertForbidden()->assertCookieMissing('laravel_session');
    }

    public function test_shareable_link_unlocks_and_strips_the_code(): void
    {
        Event::fake();

        $response = $this->get('/dashboard?tab=2&access=acme-secret-code')
            ->assertRedirect('/dashboard?tab=2')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertPlainCookie(self::COOKIE);

        $this->assertStringStartsWith('client-acme.', $response->getCookie(self::COOKIE, false)->getValue());
        $this->assertTrue($response->getCookie(self::COOKIE, false)->isHttpOnly());
        $this->assertSame('lax', $response->getCookie(self::COOKIE, false)->getSameSite());

        Event::assertDispatched(EnvironmentUnlocked::class, fn ($e) => $e->label === 'client-acme' && $e->via === 'link');
    }

    public function test_link_cookie_grants_access(): void
    {
        $cookie = $this->get('/?access=alice-secret-code')->getCookie(self::COOKIE, false);

        $this->withUnencryptedCookie(self::COOKIE, $cookie->getValue())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('dashboard');
    }

    public function test_cookie_survives_encrypt_cookies_on_web_routes(): void
    {
        $this->withUnencryptedCookie(self::COOKIE, $this->validCookie())
            ->get('/web')
            ->assertOk()
            ->assertSee('web page');
    }

    public function test_wrong_link_code_stays_locked(): void
    {
        Event::fake();

        $this->get('/dashboard?access=nope')
            ->assertForbidden()
            ->assertSee('That access code is not valid.')
            ->assertCookieMissing(self::COOKIE);

        Event::assertDispatched(UnlockFailed::class, fn ($e) => $e->via === 'link');
    }

    public function test_array_link_code_is_rejected_gracefully(): void
    {
        $this->get('/dashboard?access[]=alice-secret-code')->assertForbidden();
    }

    public function test_unlock_form_is_shown(): void
    {
        $this->get('/__access?redirect=/dashboard')
            ->assertOk()
            ->assertSee('action="/__access"', false)
            ->assertSee('value="/dashboard"', false);
    }

    public function test_form_unlocks_and_redirects_to_intended_url(): void
    {
        $this->post('/__access', ['code' => 'alice-secret-code', 'redirect' => '/dashboard?x=1'])
            ->assertRedirect('/dashboard?x=1')
            ->assertPlainCookie(self::COOKIE);
    }

    public function test_form_with_wrong_code_stays_locked(): void
    {
        $this->post('/__access', ['code' => 'wrong', 'redirect' => '/dashboard'])
            ->assertForbidden()
            ->assertSee('That access code is not valid.')
            ->assertCookieMissing(self::COOKIE);
    }

    public function test_form_rejects_cross_origin_posts(): void
    {
        $this->withHeader('Origin', 'https://evil.example')
            ->post('/__access', ['code' => 'alice-secret-code'])
            ->assertForbidden()
            ->assertCookieMissing(self::COOKIE);
    }

    public function test_form_accepts_same_origin_posts(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->post('/__access', ['code' => 'alice-secret-code'])
            ->assertRedirect('/');
    }

    #[DataProvider('unsafeRedirects')]
    public function test_form_never_redirects_off_site(string $redirect): void
    {
        $this->post('/__access', ['code' => 'alice-secret-code', 'redirect' => $redirect])
            ->assertRedirect('/');
    }

    public static function unsafeRedirects(): array
    {
        return [
            'absolute' => ['https://evil.example/'],
            'protocol relative' => ['//evil.example/'],
            'backslash' => ['/\\evil.example/'],
            'relative' => ['evil'],
        ];
    }

    public function test_unsupported_methods_on_unlock_path(): void
    {
        $this->put('/__access')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD, POST');
    }

    public function test_revoked_code_invalidates_its_cookie(): void
    {
        $cookie = $this->validCookie('client-acme');

        $this->withUnencryptedCookie(self::COOKIE, $cookie)->get('/dashboard')->assertOk();

        config(['environment-gate.codes' => 'alice:alice-secret-code']);

        $this->withUnencryptedCookie(self::COOKIE, $cookie)->get('/dashboard')->assertForbidden();
    }

    public function test_rotated_code_invalidates_its_cookie(): void
    {
        $cookie = $this->validCookie('alice');

        config(['environment-gate.codes' => 'alice:new-alice-code,client-acme:acme-secret-code']);

        $this->withUnencryptedCookie(self::COOKIE, $cookie)->get('/dashboard')->assertForbidden();
    }

    public function test_other_labels_stay_valid_when_one_is_revoked(): void
    {
        $cookie = $this->validCookie('alice');

        config(['environment-gate.codes' => 'alice:alice-secret-code']);

        $this->withUnencryptedCookie(self::COOKIE, $cookie)->get('/dashboard')->assertOk();
    }

    public function test_expired_cookie_is_rejected(): void
    {
        $this->withUnencryptedCookie(self::COOKIE, $this->validCookie('alice', time() - 1))
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_tampered_cookie_is_rejected(): void
    {
        [$label, $expires, $signature] = explode('.', $this->validCookie('alice'));

        $this->withUnencryptedCookie(self::COOKIE, "client-acme.{$expires}.{$signature}")->get('/dashboard')->assertForbidden();
        $this->withUnencryptedCookie(self::COOKIE, "{$label}.".($expires + 999999).".{$signature}")->get('/dashboard')->assertForbidden();
        $this->withUnencryptedCookie(self::COOKIE, 'garbage')->get('/dashboard')->assertForbidden();
    }

    public function test_header_grants_access_without_setting_a_cookie(): void
    {
        Event::fake();

        $this->withHeader('X-Environment-Gate', 'acme-secret-code')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJson(['name' => 'alice'])
            ->assertCookieMissing(self::COOKIE);

        Event::assertDispatched(EnvironmentUnlocked::class, fn ($e) => $e->via === 'header');
    }

    public function test_wrong_header_is_rejected(): void
    {
        $this->withHeader('X-Environment-Gate', 'wrong')->getJson('/api/me')->assertForbidden();
    }

    public function test_header_does_not_collide_with_bearer_tokens(): void
    {
        $this->withHeader('Authorization', 'Bearer some-sanctum-token')->getJson('/api/me')->assertForbidden();

        $this->withHeaders(['Authorization' => 'Bearer some-sanctum-token', 'X-Environment-Gate' => 'alice-secret-code'])
            ->getJson('/api/me')
            ->assertOk();
    }

    public function test_excepted_paths_pass(): void
    {
        $this->get('/up')->assertOk()->assertSee('healthy');
        $this->post('/webhooks/stripe')->assertOk()->assertSee('webhook received');
    }

    public function test_cors_preflight_passes(): void
    {
        $this->call('OPTIONS', '/api/me', [], [], [], [
            'HTTP_ORIGIN' => 'https://staging.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertSuccessful(); // 204 from HandleCors, or 200 where CORS is not configured
    }

    public function test_ip_allowlist(): void
    {
        config(['environment-gate.ips' => '10.0.0.0/8, 192.168.1.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get('/dashboard')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])->get('/dashboard')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.11'])->get('/dashboard')->assertForbidden();
    }

    public function test_noindex_is_always_sent(): void
    {
        $this->get('/dashboard')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/up')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->withUnencryptedCookie(self::COOKIE, $this->validCookie())->get('/dashboard')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_noindex_can_be_disabled(): void
    {
        config(['environment-gate.noindex' => false]);

        $this->get('/up')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_stealth_mode_returns_404_without_form(): void
    {
        config(['environment-gate.status' => 404]);

        $this->get('/dashboard')
            ->assertNotFound()
            ->assertDontSee('name="code"', false)
            ->assertDontSee('Private environment');

        $this->get('/__access')->assertOk()->assertSee('name="code"', false);
        $this->post('/__access', ['code' => 'wrong'])->assertForbidden();
    }

    public function test_failed_attempts_are_throttled(): void
    {
        config(['environment-gate.throttle.max_attempts' => 3]);

        foreach (range(1, 3) as $i)
        {
            $this->post('/__access', ['code' => "wrong-{$i}"])->assertForbidden();
        }

        $this->post('/__access', ['code' => 'alice-secret-code'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee('Too many attempts');

        $this->withHeader('X-Environment-Gate', 'alice-secret-code')->getJson('/api/me')->assertStatus(429);
    }

    public function test_custom_cookie_domain_and_lifetime(): void
    {
        config(['environment-gate.cookie.domain' => '.example.com', 'environment-gate.cookie.lifetime' => 60]);

        $cookie = $this->get('/?access=alice-secret-code')->getCookie(self::COOKIE, false);

        $this->assertSame('.example.com', $cookie->getDomain());
        $this->assertEqualsWithDelta(time() + 3600, $cookie->getExpiresTime(), 5);
    }

    public function test_secure_cookie_follows_request_scheme_by_default(): void
    {
        $this->assertFalse($this->get('http://localhost/?access=alice-secret-code')->getCookie(self::COOKIE, false)->isSecure());
        $this->assertTrue($this->get('https://localhost/?access=alice-secret-code')->getCookie(self::COOKIE, false)->isSecure());
    }

    public function test_disabling_at_runtime_makes_the_middleware_a_no_op(): void
    {
        config(['environment-gate.enabled' => false]);

        $this->get('/dashboard')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }
}
