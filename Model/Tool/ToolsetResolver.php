<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool;

use Magento\Framework\Exception\LocalizedException;

/**
 * Turns the toolset arguments on the endpoint URL into a {@see Toolset}.
 *
 * Every name is checked against what is actually registered, and a name that
 * matches nothing is refused rather than ignored, for the reason the Disabled
 * Tools setting refuses one: a typo in `?domains=` that was silently dropped
 * would leave a client either with far fewer tools than it was configured for
 * or — if the typo was the only entry — with no narrowing at all, and nothing
 * anywhere would say why.
 *
 * Refusing an unknown tool name does not list the registered ones. The domain
 * codes are the module's directory names and are in its README; the full tool
 * list includes tools this caller's role may not reach.
 */
class ToolsetResolver
{
    /**
     * @param ToolCatalog $catalog
     * @param ToolsetProfiles $profiles
     */
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly ToolsetProfiles $profiles
    ) {
    }

    /**
     * @param string|null $profile Raw `profile` query value.
     * @param string|null $domains Raw `domains` query value: comma separated codes.
     * @param string|null $tools Raw `tools` query value: comma separated names.
     * @return Toolset
     * @throws LocalizedException When a name matches nothing, or the selection is empty.
     */
    public function resolve(?string $profile, ?string $domains, ?string $tools): Toolset
    {
        // The common case, on every call of every unrestricted connection.
        if (trim((string) $profile) === '' && trim((string) $domains) === '' && trim((string) $tools) === '') {
            return new Toolset();
        }

        $known = array_keys($this->catalog->getDomains());

        $selected = null;
        $profile = trim((string) $profile);
        if ($profile !== '') {
            $profileDomains = $this->profiles->getDomains($profile);
            if ($profileDomains === null) {
                throw new LocalizedException(__(
                    'Unknown toolset profile "%1". Use one of: %2.',
                    $profile,
                    implode(', ', $this->profiles->getNames())
                ));
            }
            $selected = array_values(array_intersect($profileDomains, $known));
        }

        $requested = $this->split($domains);
        if ($requested !== []) {
            $unknown = array_diff($requested, $known);
            if ($unknown !== []) {
                throw new LocalizedException(__(
                    'Unknown tool domain(s) in the endpoint URL: %1. This server has: %2.',
                    implode(', ', $unknown),
                    implode(', ', $known)
                ));
            }
            $selected = $selected === null ? $requested : array_values(array_intersect($selected, $requested));
        }

        if ($selected === []) {
            throw new LocalizedException(__(
                'The toolset in the endpoint URL selects no domain at all. Check that profile and '
                . 'domains overlap.'
            ));
        }

        $names = $this->split($tools);
        if ($names !== []) {
            $unknown = array_diff($names, $this->catalog->getToolNames());
            if ($unknown !== []) {
                throw new LocalizedException(__(
                    'Unknown tool name(s) in the endpoint URL: %1.',
                    implode(', ', $unknown)
                ));
            }
        }

        return new Toolset($selected, $names === [] ? null : $names);
    }

    /**
     * Split a query value into distinct, lower-cased entries.
     *
     * @param string|null $raw
     * @return string[]
     */
    private function split(?string $raw): array
    {
        $parts = preg_split('/[\s,]+/', strtolower((string) $raw)) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $part): bool => $part !== '')));
    }
}
