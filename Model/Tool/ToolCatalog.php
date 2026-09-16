<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool;

use Magenx\AiMcp\Api\ToolInterface;

/**
 * Groups the registered tools into domains an operator can switch on and off.
 *
 * The domain is derived from the tool's class rather than declared, for the
 * same reason the annotations are derived from `isWrite()`: a list kept by hand
 * beside 170 tools is a list that is wrong within a release. The directory a
 * tool already lives in is the grouping its author chose, so that is the one
 * used.
 *
 * Nothing here is a security boundary — ACL is. This decides only what an
 * operator wants a client to *see*, which is a context-window question.
 */
class ToolCatalog
{
    /** The namespace fragment every tool in this module sits under. */
    private const TOOL_NAMESPACE = '\\Model\\Tool\\';

    /** Domain used when a class name yields nothing usable at all. */
    private const FALLBACK_DOMAIN = 'other';

    /** @var array<string, string>|null */
    private ?array $domains = null;

    /**
     * @param ToolRegistry $registry
     */
    public function __construct(
        private readonly ToolRegistry $registry
    ) {
    }

    /**
     * The domain code for one tool.
     *
     * `Model\Tool\Sales\SearchOrders` and `Model\Tool\Catalog\Option\SaveProductOption`
     * both yield the segment immediately after `Model\Tool\`, so a nested
     * directory groups with its parent rather than becoming a domain of its own
     * that an operator has to know to tick separately.
     *
     * A tool contributed by another module need not sit under that namespace at
     * all. Those fall back to vendor and module — `Acme\Foo\Tool\Bar` becomes
     * `acme_foo` — which is more useful than one bucket holding every foreign
     * tool, because it lets an operator switch a whole third-party module off.
     *
     * @param ToolInterface $tool
     * @return string
     */
    public function getDomain(ToolInterface $tool): string
    {
        return strtolower($this->segment($tool::class));
    }

    /**
     * Every domain in the registry, as code => label, sorted by label.
     *
     * @return array<string, string>
     */
    public function getDomains(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }

        $domains = [];
        foreach ($this->registry->getAll() as $tool) {
            $segment = $this->segment($tool::class);
            $domains[strtolower($segment)] = $this->label($segment);
        }
        asort($domains);

        $this->domains = $domains;

        return $domains;
    }

    /**
     * Every registered tool name, for validating a denylist against.
     *
     * @return string[]
     */
    public function getToolNames(): array
    {
        return array_keys($this->registry->getAll());
    }

    /**
     * The raw namespace segment a class's domain comes from, before casing.
     *
     * @param string $class
     * @return string
     */
    private function segment(string $class): string
    {
        $position = strpos($class, self::TOOL_NAMESPACE);
        if ($position !== false) {
            $rest = explode('\\', substr($class, $position + strlen(self::TOOL_NAMESPACE)));
            // A tool sitting directly in Model\Tool\ has no domain segment, and
            // taking the first one there would invent a domain named after the
            // class. Only a segment followed by more namespace is a directory.
            if (count($rest) > 1 && $rest[0] !== '') {
                return $rest[0];
            }
        }

        $parts = explode('\\', $class);
        // An anonymous class stringifies as "class@anonymous/path/to/file:12$0",
        // which has no meaningful vendor or module. Test fixtures are the only
        // ones that reach this.
        if (count($parts) >= 2 && !str_contains($class, '@anonymous')) {
            return $parts[0] . '_' . $parts[1];
        }

        return self::FALLBACK_DOMAIN;
    }

    /**
     * Turn a namespace segment into something readable in a config multiselect:
     * `MediaGallery` into "Media Gallery", `UrlRewrite` into "URL Rewrite".
     *
     * @param string $segment
     * @return string
     */
    private function label(string $segment): string
    {
        $words = preg_split('/(?<=[a-z0-9])(?=[A-Z])|_/', $segment) ?: [$segment];

        return implode(' ', array_map(
            static fn (string $word): string => Initialisms::MAP[strtolower($word)] ?? ucfirst($word),
            $words
        ));
    }
}
