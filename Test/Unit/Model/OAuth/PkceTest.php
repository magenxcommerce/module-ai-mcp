<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\Pkce;
use PHPUnit\Framework\TestCase;

/**
 * S256 against the RFC 7636 test vector, and refusal of everything else.
 *
 * @see Pkce
 */
class PkceTest extends TestCase
{
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /**
     * @return void
     */
    public function testRfcVectorVerifies(): void
    {
        $this->assertTrue((new Pkce())->verify(self::VERIFIER, self::CHALLENGE));
    }

    /**
     * @return void
     */
    public function testAnyOtherVerifierFails(): void
    {
        $this->assertFalse((new Pkce())->verify(strrev(self::VERIFIER), self::CHALLENGE));
    }

    /**
     * A `plain` client sends the verifier itself as the challenge; that must never pass as S256.
     *
     * @return void
     */
    public function testPlainChallengeDoesNotVerify(): void
    {
        $this->assertFalse((new Pkce())->verify(self::VERIFIER, self::VERIFIER));
    }

    /**
     * @return void
     */
    public function testVerifierOutsideTheRfcLengthIsRefused(): void
    {
        $pkce = new Pkce();
        $this->assertFalse($pkce->verify(str_repeat('a', 42), self::CHALLENGE));
        $this->assertFalse($pkce->verify(str_repeat('a', 129), self::CHALLENGE));
        $this->assertFalse($pkce->verify('', self::CHALLENGE));
    }

    /**
     * @return void
     */
    public function testChallengeShape(): void
    {
        $pkce = new Pkce();
        $this->assertTrue($pkce->isValidChallenge(self::CHALLENGE));
        $this->assertFalse($pkce->isValidChallenge(self::CHALLENGE . '='));
        $this->assertFalse($pkce->isValidChallenge(substr(self::CHALLENGE, 1)));
        $this->assertFalse($pkce->isValidChallenge(''));
    }
}
