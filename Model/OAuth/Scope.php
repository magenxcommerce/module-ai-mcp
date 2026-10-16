<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

/**
 * The two scopes a grant can carry.
 *
 * Scope narrows, it never widens: `mcp:write` lets a connection call write
 * tools only where the admin's own role and the store's write switch already
 * allow it. A grant without it is read-only whatever the role says.
 */
class Scope
{
    public const READ = 'mcp:read';
    public const WRITE = 'mcp:write';

    /** @var string[] */
    public const SUPPORTED = [self::READ, self::WRITE];

    /**
     * Parse a space-separated scope string.
     *
     * An empty request means "whatever the admin chooses to grant", which the
     * consent screen offers as read with write as an opt-in.
     *
     * @param string|null $raw
     * @return string[]
     * @throws OAuthException When an unknown scope is requested.
     */
    public function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return self::SUPPORTED;
        }

        $scopes = array_values(array_unique(preg_split('/ +/', $raw) ?: []));
        foreach ($scopes as $scope) {
            if (!in_array($scope, self::SUPPORTED, true)) {
                throw new OAuthException('invalid_scope', sprintf('Unsupported scope "%s".', $scope));
            }
        }

        return $this->normalize($scopes);
    }

    /**
     * Canonical order, and write always implies read.
     *
     * @param string[] $scopes
     * @return string[]
     */
    public function normalize(array $scopes): array
    {
        $result = [];
        if (in_array(self::READ, $scopes, true) || in_array(self::WRITE, $scopes, true)) {
            $result[] = self::READ;
        }
        if (in_array(self::WRITE, $scopes, true)) {
            $result[] = self::WRITE;
        }

        return $result;
    }

    /**
     * Join scopes for storage and for the wire.
     *
     * @param string[] $scopes
     * @return string
     */
    public function format(array $scopes): string
    {
        return implode(' ', $this->normalize($scopes));
    }
}
