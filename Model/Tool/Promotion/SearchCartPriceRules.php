<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\SalesRule\Api\RuleRepositoryInterface;

/**
 * Find cart price rules.
 */
class SearchCartPriceRules extends AbstractTool
{
    /**
     * @param RuleRepositoryInterface $ruleRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param CartPriceRuleProjector $projector
     */
    public function __construct(
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly CartPriceRuleProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_cart_price_rules';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List cart price rules, which are the discounts applied to a cart and the rules '
            . 'coupons belong to. Returns a summary per rule; use get_cart_price_rule for its '
            . 'websites, customer groups and condition trees.';
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
                    'is_active' => ['type' => 'boolean', 'description' => 'Restrict to active or inactive rules.'],
                    'coupon_type' => [
                        'type' => 'integer',
                        'description' => '1 = no coupon, applies automatically; 2 = a specific '
                            . 'coupon code; 3 = auto-generated coupons.',
                    ],
                    'sort_by' => [
                        'type' => 'string',
                        'description' => 'Field to sort by. Defaults to sort_order, which is the '
                            . 'order Magento applies the rules in.',
                    ],
                    'sort_direction' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_SalesRule::quote';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $name = $this->optionalString($arguments, 'name');
        if ($name !== null) {
            $this->searchCriteriaBuilder->addFilter('name', '%' . $name . '%', 'like');
        }

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $this->searchCriteriaBuilder->addFilter('is_active', $isActive ? 1 : 0);
        }

        $couponType = $this->optionalInt($arguments, 'coupon_type');
        if ($couponType !== null) {
            $this->searchCriteriaBuilder->addFilter('coupon_type', $couponType);
        }

        $sortBy = $this->optionalString($arguments, 'sort_by', 'sort_order');
        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'ASC'));
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->setField((string) $sortBy)
                ->setDirection($direction === 'DESC' ? 'DESC' : 'ASC')
                ->create()
        );

        $result = $this->ruleRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                fn ($rule): array => $this->projector->toSummary($rule),
                array_values($result->getItems())
            ),
        ];
    }
}
