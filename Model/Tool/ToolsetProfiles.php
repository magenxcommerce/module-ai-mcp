<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool;

/**
 * Named slices of the toolset, for `?profile=` on the endpoint URL.
 *
 * A profile is a shorthand for a domain list and nothing more: it grants
 * nothing, and the server intersects it with everything else that narrows the
 * toolset. Each one is the set of domains a typical task in that area reaches
 * for, plus `store`, because almost every task starts by asking which store
 * view it is working in.
 *
 * A profile may name a domain that no registered tool belongs to — a domain
 * contributed by a module that is not installed here. That is skipped rather
 * than refused, so a profile keeps working on a store that has less in it.
 */
class ToolsetProfiles
{
    /** @var array<string, string[]> */
    private const PROFILES = [
        'analytics' => [
            'report', 'sales', 'customer', 'quote', 'promotion', 'inventory', 'search', 'review', 'store',
        ],
        'catalog' => [
            'catalog', 'category', 'attribute', 'configurable', 'bundle', 'downloadable', 'link',
            'media', 'attachment', 'mediagallery', 'price', 'inventory', 'urlrewrite', 'productfeed',
            'quicksearch', 'review', 'search', 'store',
        ],
        'content' => ['cms', 'widget', 'email', 'design', 'mediagallery', 'blog', 'store'],
        'support' => ['sales', 'customer', 'quote', 'rma', 'helpdesk', 'gdpr', 'user', 'review', 'store'],
        'ops' => ['ops', 'log', 'config', 'platform', 'security', 'adminactivity', 'store'],
    ];

    /**
     * Every profile name, in the order they are offered.
     *
     * @return string[]
     */
    public function getNames(): array
    {
        return array_keys(self::PROFILES);
    }

    /**
     * The domains one profile stands for, or null if there is no such profile.
     *
     * @param string $name
     * @return string[]|null
     */
    public function getDomains(string $name): ?array
    {
        return self::PROFILES[$name] ?? null;
    }
}
