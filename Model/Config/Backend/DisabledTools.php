<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Config\Backend;

use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Refuses a Disabled Tools list naming a tool that does not exist.
 *
 * A denylist is the one kind of setting whose typo is invisible: the entry
 * matches nothing, the tool it was meant to hide stays listed and callable, and
 * the admin page saves without complaint. The operator then believes a tool is
 * off when it is on — which, on a list whose whole purpose is withholding
 * capability, is the wrong direction to fail in.
 *
 * So the save is refused, and the message names the near miss rather than only
 * the mistake: the usual cause is a plural, a tense or a remembered name from
 * another server.
 */
class DisabledTools extends Value
{
    /** How far a real name may be from the typo and still be worth offering. */
    private const MAX_SUGGESTION_DISTANCE = 4;

    /** Suggesting a dozen names is not a suggestion. */
    private const MAX_SUGGESTIONS = 3;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ToolCatalog $catalog
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ToolCatalog $catalog,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $known = $this->catalog->getToolNames();

        foreach ($this->parse((string) $this->getValue()) as $name) {
            if (in_array($name, $known, true)) {
                continue;
            }

            throw new LocalizedException(__(
                'No tool is named "%1". %2',
                $name,
                $this->suggestion($name, $known)
            ));
        }

        parent::beforeSave();

        return $this;
    }

    /**
     * Same splitting the runtime does, so what validates here is what filters
     * there.
     *
     * @param string $raw
     * @return string[]
     * @see \Magenx\AiMcp\Model\Config::getDisabledTools
     */
    private function parse(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param string $name
     * @param string[] $known
     * @return string
     */
    private function suggestion(string $name, array $known): string
    {
        $near = [];
        foreach ($known as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance <= self::MAX_SUGGESTION_DISTANCE) {
                $near[$candidate] = $distance;
            }
        }

        if ($near === []) {
            return (string) __('This server registers %1 tools; tools/list reports them.', count($known));
        }

        asort($near);
        $closest = array_slice(array_keys($near), 0, self::MAX_SUGGESTIONS);

        return (string) __('Did you mean: %1?', implode(', ', $closest));
    }
}
