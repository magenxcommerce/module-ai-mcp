<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\CatalogRule\Api\Data\RuleInterface;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory;

/**
 * List the catalog price rules.
 *
 * Magento gives catalog price rules no list operation in its service contracts
 * — the repository can only fetch one by id — so this reads the rule collection
 * directly, which is what the admin grid does. Every other catalog rule tool
 * here goes through the repository.
 */
class SearchCatalogPriceRules extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param CatalogPriceRuleProjector $projector
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly CatalogPriceRuleProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_catalog_price_rules';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List catalog price rules, which change product prices themselves rather than '
            . 'discounting a cart. Use this to find a rule_id for get_catalog_price_rule or '
            . 'update_catalog_price_rule.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Free text matched as a substring against the rule name.',
                    ],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'Restrict to active or inactive rules.',
                    ],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
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
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();

        $name = $this->optionalString($arguments, 'name');
        if ($name !== null) {
            $collection->addFieldToFilter('name', ['like' => '%' . $name . '%']);
        }

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }

        $collection->setOrder('sort_order', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $rule) {
            /** @var RuleInterface $rule */
            $items[] = $this->projector->toArray($rule);
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
