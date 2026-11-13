<?php

namespace FullSmack\EnvironmentGate\Tests\Unit;

use FullSmack\EnvironmentGate\Codes;
use FullSmack\EnvironmentGate\CookieSigner;
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
        $this->assertSame('alice', $codes->match('one'));
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
        $value = $signer->issue('alice', 'secret', 2000);

        $this->assertSame('alice', $signer->verify($value, $codes, 1999));
        $this->assertNull($signer->verify($value, $codes, 2000));
        $this->assertNull((new CookieSigner('other-key'))->verify($value, $codes, 1999));
        $this->assertNull($signer->verify($value, Codes::parse('alice:rotated'), 1999));
    }

    public function test_signer_requires_a_key(): void
    {
        $this->expectException(RuntimeException::class);

        new CookieSigner('');
    }
}
