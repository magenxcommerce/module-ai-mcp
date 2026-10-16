<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

/**
 * Which redirect URIs a client may register and be sent back to.
 *
 * This is what makes open dynamic client registration safe to offer. Anyone
 * can register a client, but only with a callback the operator listed —
 * claude.ai's by default — so an authorization code can only ever be
 * delivered to a service the operator chose to trust, never to a server an
 * attacker controls. Matching is exact: no prefixes, no wildcards, no
 * normalisation that could make two different URLs compare equal.
 *
 * The one exception is the loopback rule of RFC 8252 §7.3, off by default:
 * a native client such as Claude Code or the MCP Inspector listens on an
 * ephemeral port, so http://localhost, http://127.0.0.1 and http://[::1]
 * match on any port when the operator turns it on.
 */
class RedirectUriPolicy
{
    private const LOOPBACK_PATTERN = '#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?(/[^\s?\#]*)?$#';

    /**
     * @param OAuthConfig $config
     */
    public function __construct(
        private readonly OAuthConfig $config
    ) {
    }

    /**
     * Whether this URI may be registered or redirected to.
     *
     * @param string $uri
     * @return bool
     */
    public function isAllowed(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 2048 || preg_match('/[\s\x00-\x1f]/', $uri) === 1) {
            return false;
        }

        // RFC 6749 §3.1.2: a redirect URI must not carry a fragment.
        if (str_contains($uri, '#')) {
            return false;
        }

        if (in_array($uri, $this->config->getAllowedRedirectUris(), true)) {
            return true;
        }

        return $this->config->isLoopbackAllowed() && preg_match(self::LOOPBACK_PATTERN, $uri) === 1;
    }

    /**
     * The host part of a URI, for showing an admin where the code will go.
     *
     * @param string $uri
     * @return string
     */
    public function describe(string $uri): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://([^/?\#]+)#i', $uri, $matches) === 1) {
            return $matches[1];
        }

        return $uri;
    }
}
