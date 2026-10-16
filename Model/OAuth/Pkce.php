<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

/**
 * Proof Key for Code Exchange (RFC 7636), S256 only.
 *
 * OAuth 2.1 and the MCP authorization spec both require PKCE for every
 * client. `plain` is refused: it protects nothing against an attacker who
 * can read the authorization request, which is the attacker PKCE exists for.
 */
class Pkce
{
    public const METHOD_S256 = 'S256';

    /** RFC 7636 §4.1: 43–128 characters from the unreserved set. */
    private const VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    /** A base64url SHA-256 digest with no padding is always exactly 43 characters. */
    private const CHALLENGE_PATTERN = '/^[A-Za-z0-9\-_]{43}$/';

    /**
     * Whether a code_challenge is a well-formed S256 challenge.
     *
     * @param string $challenge
     * @return bool
     */
    public function isValidChallenge(string $challenge): bool
    {
        return preg_match(self::CHALLENGE_PATTERN, $challenge) === 1;
    }

    /**
     * Whether a code_verifier hashes to the stored challenge.
     *
     * @param string $verifier
     * @param string $challenge
     * @return bool
     */
    public function verify(string $verifier, string $challenge): bool
    {
        if (preg_match(self::VERIFIER_PATTERN, $verifier) !== 1) {
            return false;
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }
}
