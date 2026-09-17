<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Schedule a rebuild of catalog rule prices.
 *
 * The admin's "Apply Rules" button does not recompute prices inline either — it
 * marks the catalog rule indexer invalid and lets cron do the work. This does
 * the same thing, for the same reason {@see \Magenx\AiMcp\Model\Tool\Ops\InvalidateIndexers}
 * gives: re-applying rules across a real catalogue takes minutes to hours, so
 * running it inside an HTTP request would hold a worker until the request timed
 * out and leave the caller unable to tell success from failure.
 *
 * So the honest answer is "scheduled", and the result says when it will happen
 * rather than implying the prices have already moved. Every catalog rule tool
 * that changes prices — create, update, delete — points here.
 */
class ApplyCatalogPriceRules extends AbstractTool
{
    /** The indexer that recomputes which products each rule prices, and at what. */
    private const RULE_INDEXER = 'catalogrule_rule';

    /**
     * @param IndexerRegistry $indexerRegistry
     */
    public function __construct(private readonly IndexerRegistry $indexerRegistry)
    {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'apply_catalog_price_rules';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Schedule catalog price rules to be re-applied, so changes made with '
            . 'create_catalog_price_rule, update_catalog_price_rule or delete_catalog_price_rule '
            . 'reach storefront prices. This does not re-price anything immediately — it marks the '
            . 'catalog rule indexer invalid and the next indexer cron run rebuilds it, which is '
            . 'what the admin\'s "Apply Rules" button does too. For an immediate rebuild run '
            . '"bin/magento indexer:reindex catalogrule_rule" on the server.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_CatalogRule::promo_catalog';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function isDestructive(): bool
    {
        // Schedules a rebuild of derived data; no rule and no product is changed.
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
    {
        // An indexer that is already invalid stays invalid.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        try {
            $indexer = $this->indexerRegistry->get(self::RULE_INDEXER);
        } catch (\InvalidArgumentException) {
            throw new LocalizedException(__(
                'The "%1" indexer is not registered on this installation, so catalog price rules '
                . 'cannot be scheduled from here. Check that Magento_CatalogRule is enabled.',
                self::RULE_INDEXER
            ));
        }

        $indexer->invalidate();

        return [
            'scheduled' => true,
            'tool' => $this->getName(),
            'indexer' => self::RULE_INDEXER,
            'note' => 'Storefront prices change once the indexer cron run rebuilds this index, '
                . 'not at the moment of this call.',
        ];
    }
}
