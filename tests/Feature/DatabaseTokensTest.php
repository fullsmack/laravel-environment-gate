<?php

namespace FullSmack\EnvironmentGate\Tests\Feature;

use FullSmack\EnvironmentGate\Database\TokenRepository;
use FullSmack\EnvironmentGate\Database\TokenTable;
use FullSmack\EnvironmentGate\Events\EnvironmentUnlocked;
use FullSmack\EnvironmentGate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class DatabaseTokensTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'environment_gate';
    private const TOKEN = 'db-token-0123456789abcdef';

    protected bool $databaseTokens = true;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function table(): string
    {
        return TokenTable::name();
    }

    public function test_migration_is_loaded_where_the_package_is_enabled(): void
    {
        $this->assertContains(
            realpath(__DIR__.'/../../database/migrations'),
            array_map('realpath', $this->app->make('migrator')->paths()),
        );
    }

    private function createToken(string $label = 'client-db', string $token = self::TOKEN, array $options = []): void
    {
        $this->artisan('environment-gate:token:create', ['label' => $label, '--token' => $token, ...$options])
            ->assertSuccessful();
    }

    private function unlockCookie(string $token = self::TOKEN): string
    {
        return $this->get('/?access='.$token)->assertRedirect('/')->getCookie(self::COOKIE, false)->getValue();
    }

    public function test_migration_creates_the_table(): void
    {
        $this->assertTrue(Schema::hasTable($this->table()));
    }

    public function test_create_command_stores_only_a_hash_and_prints_the_token_once(): void
    {
        config(['app.url' => 'https://staging.example.com']);

        $this->artisan('environment-gate:token:create', ['label' => 'client-db', '--token' => self::TOKEN, '--description' => 'Acme demo'])
            ->expectsOutputToContain('Link    https://staging.example.com/?access='.self::TOKEN)
            ->expectsOutputToContain('X-Environment-Gate: '.self::TOKEN)
            ->assertSuccessful();

        $row = DB::table($this->table())->first();

        $this->assertSame('client-db', $row->label);
        $this->assertSame(hash('sha256', self::TOKEN), $row->token_hash);
        $this->assertSame('Acme demo', $row->description);
        $this->assertDatabaseMissing($this->table(), ['token_hash' => self::TOKEN]);
    }

    public function test_create_command_generates_a_random_token(): void
    {
        $this->artisan('environment-gate:token:create', ['label' => 'random', '--length' => 48])
            ->expectsOutputToContain('This is the only time the token is shown')
            ->assertSuccessful();

        $this->assertDatabaseHas($this->table(), ['label' => 'random']);
    }

    public function test_database_token_link_unlocks(): void
    {
        Event::fake([EnvironmentUnlocked::class]);
        $this->createToken();

        $cookie = $this->unlockCookie();

        $this->assertStringStartsWith('client-db.', $cookie);
        $this->withUnencryptedCookie(self::COOKIE, $cookie)->get('/dashboard')->assertOk();

        Event::assertDispatched(EnvironmentUnlocked::class, fn ($e) => $e->label === 'client-db' && $e->source === 'database');
    }

    public function test_database_token_form_and_header_unlock(): void
    {
        $this->createToken();

        $this->post('/__access', ['code' => self::TOKEN, 'redirect' => '/dashboard'])->assertRedirect('/dashboard');
        $this->withHeader('X-Environment-Gate', self::TOKEN)->getJson('/api/me')->assertOk();
    }

    public function test_config_codes_keep_working_next_to_database_tokens(): void
    {
        $this->createToken();

        $this->get('/?access=alice-secret-code')->assertRedirect('/');
    }

    public function test_wrong_token_stays_locked(): void
    {
        $this->createToken();

        $this->get('/?access=db-token-wrong')->assertForbidden();
    }

    public function test_revoking_a_token_logs_its_holders_out_immediately(): void
    {
        $this->createToken();
        $cookie = $this->unlockCookie();

        // Warm the lookup cache.
        $this->withUnencryptedCookie(self::COOKIE, $cookie)->get('/dashboard')->assertOk();

        $this->artisan('environment-gate:token:revoke', ['label' => 'client-db'])->assertSuccessful();

        $this->withUnencryptedCookie(self::COOKIE, $cookie)->get('/dashboard')->assertForbidden();
        $this->get('/?access='.self::TOKEN)->assertForbidden();
    }

    public function test_replacing_a_token_requires_force_and_invalidates_old_cookies(): void
    {
        $this->createToken();
        $cookie = $this->unlockCookie();

        $this->artisan('environment-gate:token:create', ['label' => 'client-db', '--token' => 'another-token-0123456789'])
            ->assertFailed();

        $this->createToken('client-db', 'another-token-0123456789', ['--force' => true]);

        $this->withUnencryptedCookie(self::COOKIE, $cookie)->get('/dashboard')->assertForbidden();
        $this->get('/?access='.self::TOKEN)->assertForbidden();
        $this->get('/?access=another-token-0123456789')->assertRedirect('/');
    }

    public function test_expired_tokens_are_rejected_and_cookies_do_not_outlive_them(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $this->createToken(options: ['--expires' => '2']);

        $cookie = $this->get('/?access='.self::TOKEN)->getCookie(self::COOKIE, false);

        $this->assertEqualsWithDelta(Carbon::now()->addDays(2)->getTimestamp(), $cookie->getExpiresTime(), 5);

        DB::table($this->table())->update(['expires_at' => Carbon::now()->subMinute()]);
        config(['environment-gate.database.cache_seconds' => 0]);

        $this->withUnencryptedCookie(self::COOKIE, $cookie->getValue())->get('/dashboard')->assertForbidden();
        $this->get('/?access='.self::TOKEN)->assertForbidden();
    }

    public function test_expires_accepts_a_date(): void
    {
        $this->createToken(options: ['--expires' => Carbon::now()->addYear()->toDateString()]);

        $this->assertNotNull(DB::table($this->table())->value('expires_at'));
    }

    public function test_create_command_validates_input(): void
    {
        $this->artisan('environment-gate:token:create', ['label' => 'bad label', '--token' => self::TOKEN])->assertFailed();
        $this->artisan('environment-gate:token:create', ['label' => 'alice', '--token' => self::TOKEN])->assertFailed();
        $this->artisan('environment-gate:token:create', ['label' => 'short', '--token' => 'too-short'])->assertFailed();
        $this->artisan('environment-gate:token:create', ['label' => 'reuse', '--token' => 'alice-secret-code'])->assertFailed();
        $this->artisan('environment-gate:token:create', ['label' => 'past', '--token' => self::TOKEN, '--expires' => '2001-01-01'])->assertFailed();
        $this->artisan('environment-gate:token:create', ['label' => 'nonsense', '--token' => self::TOKEN, '--expires' => 'not a date'])->assertFailed();

        $this->assertSame(0, DB::table($this->table())->count());
    }

    public function test_list_command_shows_codes_and_tokens_without_secrets(): void
    {
        $this->createToken(options: ['--description' => 'Acme demo']);

        $this->artisan('environment-gate:token:list')
            ->expectsOutputToContain('| alice')
            ->expectsOutputToContain('Acme demo')
            ->doesntExpectOutputToContain(self::TOKEN)
            ->doesntExpectOutputToContain('alice-secret-code')
            ->assertSuccessful();
    }

    public function test_revoke_command(): void
    {
        $this->artisan('environment-gate:token:revoke', ['label' => 'missing'])->assertFailed();
        $this->artisan('environment-gate:token:revoke', ['label' => 'alice'])
            ->expectsOutputToContain('ENVIRONMENT_GATE_CODES')
            ->assertFailed();
        $this->artisan('environment-gate:token:revoke')->assertFailed();
    }

    public function test_revoke_expired(): void
    {
        $this->createToken('old', 'old-token-0123456789abc');
        $this->createToken('fresh', 'fresh-token-0123456789abc');
        DB::table($this->table())->where('label', 'old')->update(['expires_at' => Carbon::now()->subDay()]);

        $this->artisan('environment-gate:token:revoke', ['--expired' => true])
            ->expectsOutputToContain('old')
            ->assertSuccessful();

        $this->assertSame(['fresh'], DB::table($this->table())->pluck('label')->all());
    }

    public function test_link_command_explains_database_tokens(): void
    {
        $this->createToken();

        $this->artisan('environment-gate:link', ['label' => 'client-db'])
            ->expectsOutputToContain('database token')
            ->assertFailed();
    }

    public function test_locked_and_forged_requests_never_query_the_database(): void
    {
        $this->createToken();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get('/dashboard')->assertForbidden();
        $this->withUnencryptedCookie(self::COOKIE, 'client-db.9999999999.forged.forged')->get('/dashboard')->assertForbidden();

        $this->assertSame(0, $queries);
    }

    public function test_cookie_lookups_are_cached(): void
    {
        $this->createToken();
        $cookie = $this->unlockCookie();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        foreach (range(1, 3) as $i)
        {
            $this->withUnencryptedCookie(self::COOKIE, $cookie)->get('/dashboard')->assertOk();
        }

        $this->assertSame(1, $queries);
    }

    public function test_last_used_is_recorded(): void
    {
        $this->createToken();

        $this->assertNull(DB::table($this->table())->value('last_used_at'));

        $this->unlockCookie();

        $this->assertNotNull(DB::table($this->table())->value('last_used_at'));
    }

    public function test_missing_table_fails_closed_without_breaking_config_codes(): void
    {
        Schema::drop($this->table());

        $this->get('/?access='.self::TOKEN)->assertForbidden();
        $this->get('/?access=alice-secret-code')->assertRedirect('/');
    }

    public function test_repository_ignores_tokens_when_database_is_disabled(): void
    {
        $this->createToken();
        config(['environment-gate.database.enabled' => false]);

        $this->get('/?access='.self::TOKEN)->assertForbidden();
        $this->assertTrue($this->app->make(TokenRepository::class)->exists('client-db'));
    }
}
