<?php

namespace FullSmack\EnvironmentGate\Tests\Unit;

use FullSmack\EnvironmentGate\Codes;
use FullSmack\EnvironmentGate\Contracts\CredentialProvider;
use FullSmack\EnvironmentGate\CookieSigner;
use FullSmack\EnvironmentGate\Credential;
use FullSmack\EnvironmentGate\Credentials;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CodesTest extends TestCase
{
    public function test_parses_labelled_codes(): void
    {
        $codes = Codes::parse(' alice:one , client-acme:two:with:colons ,, ');

        $this->assertSame(['alice', 'client-acme'], $codes->labels());
        $this->assertSame('two:with:colons', $codes->get('client-acme'));
        $this->assertSame('alice', $codes->match('one')?->label);
        $this->assertSame('config', $codes->match('one')?->source);
        $this->assertNull($codes->match('nope'));
        $this->assertNull($codes->match(''));
    }

    public function test_parses_unlabelled_codes_and_arrays(): void
    {
        $this->assertSame(['code-1', 'code-2'], Codes::parse('first,second')->labels());
        $this->assertSame('x', Codes::parse(['team' => 'x'])->get('team'));
        $this->assertTrue(Codes::parse(null)->isEmpty());
        $this->assertTrue(Codes::parse('')->isEmpty());
    }

    public function test_rejects_invalid_labels(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Codes::parse('bad label:code');
    }

    public function test_rejects_duplicate_labels(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Codes::parse('a:one,a:two');
    }

    public function test_rejects_empty_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Codes::parse('a:');
    }

    public function test_signer_round_trip(): void
    {
        $codes = Codes::parse('alice:secret');
        $signer = new CookieSigner('key');
        $value = $signer->issue($codes->find('alice'), 2000);

        $this->assertSame('alice', $signer->verify($value, $codes, 1999)?->label);
        $this->assertNull($signer->verify($value, $codes, 2000));
        $this->assertNull((new CookieSigner('other-key'))->verify($value, $codes, 1999));
        $this->assertNull($signer->verify($value, Codes::parse('alice:rotated'), 1999));
    }

    public function test_signer_never_looks_up_credentials_for_forged_cookies(): void
    {
        $lookups = new class () implements CredentialProvider {
            public int $finds = 0;

            public function match(string $input): ?Credential
            {
                return null;
            }

            public function find(string $label): ?Credential
            {
                $this->finds++;

                return null;
            }
        };

        $signer = new CookieSigner('key');
        $forged = (new CookieSigner('attacker-key'))->issue(new Credential('alice', 'x', 'database'), 5000);

        $this->assertNull($signer->verify($forged, $lookups, 1000));
        $this->assertNull($signer->verify('alice.5000.abc.def', $lookups, 1000));
        $this->assertSame(0, $lookups->finds);

        $this->assertNull($signer->verify($signer->issue(new Credential('alice', 'x', 'database'), 5000), $lookups, 1000));
        $this->assertSame(1, $lookups->finds);
    }

    public function test_signer_rejects_expired_credentials(): void
    {
        $signer = new CookieSigner('key');
        $credential = new Credential('alice', 'x', 'database', expiresAt: 1500);
        $provider = new Credentials([new class ($credential) implements CredentialProvider {
            public function __construct(private Credential $credential) {}

            public function match(string $input): ?Credential
            {
                return null;
            }

            public function find(string $label): ?Credential
            {
                return $this->credential;
            }
        }]);

        $value = $signer->issue($credential, 5000);

        $this->assertSame('alice', $signer->verify($value, $provider, 1000)?->label);
        $this->assertNull($signer->verify($value, $provider, 1500));
    }

    public function test_signer_requires_a_key(): void
    {
        $this->expectException(RuntimeException::class);

        new CookieSigner('');
    }
}
