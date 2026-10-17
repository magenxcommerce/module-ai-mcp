<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

/**
 * The tool-domain lists a client and a grant carry, as stored: JSON, or NULL for every domain.
 *
 * A client's list is the cap an administrator set on it; a grant's is what
 * the approving admin ticked within that cap. What a connection may use is
 * the two intersected, worked out at every call, so narrowing a client's cap
 * narrows its live connections at once. Like every other toolset setting it
 * only removes tools: ACL, scope and the store's own settings still apply.
 */
class DomainList
{
    /**
     * @param string|null $stored
     * @return string[]|null Null for every domain.
     */
    public function decode(?string $stored): ?array
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $domains = json_decode($stored, true);
        if (!is_array($domains)) {
            // Unreadable is not "everything": fail closed.
            return [];
        }

        return array_values(array_unique(array_filter($domains, 'is_string')));
    }

    /**
     * @param string[]|null $domains
     * @return string|null
     */
    public function encode(?array $domains): ?string
    {
        if ($domains === null) {
            return null;
        }

        $domains = array_values(array_unique($domains));
        sort($domains);

        return (string) json_encode($domains, JSON_UNESCAPED_SLASHES);
    }

    /**
     * The domains both lists allow; null only when neither restricts.
     *
     * @param string[]|null $first
     * @param string[]|null $second
     * @return string[]|null
     */
    public function intersect(?array $first, ?array $second): ?array
    {
        if ($first === null) {
            return $second;
        }
        if ($second === null) {
            return $first;
        }

        return array_values(array_intersect($first, $second));
    }
}
