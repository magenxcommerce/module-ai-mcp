<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Find carts, including the ones nobody ever came back to.
 *
 * This is the read side of abandoned checkout. Every cart a customer started
 * and left is still in the quote table, and until now nothing in this server
 * could see one — so "why did takings drop on Tuesday" had no answer beyond the
 * orders that were actually placed.
 *
 * Two things to know before reading the results. An **inactive** cart is not an
 * abandoned one: placing an order deactivates the quote and records what it
 * became, so `is_active: false` with a `reserved_order_id` means bought, and
 * `is_active: true` with an old `updated_at` means abandoned. And no money is
 * reported here at all — a cart carries none, and fetching totals is a service
 * call per cart, which a page of them cannot afford. get_cart reads one in full.
 */
class SearchCarts extends AbstractTool
{
    /**
     * @param CartRepositoryInterface $cartRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param CartProjector $projector
     */
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly CartProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_carts';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Find shopping carts, including abandoned ones — every cart a customer started and '
            . 'left is still here. At least one filter is required, because an unfiltered lookup '
            . 'returns every quote the store has ever held. Note that is_active false does NOT '
            . 'mean abandoned: placing an order deactivates the cart and sets reserved_order_id, '
            . 'so an abandoned cart is an ACTIVE one that stopped being updated. No totals are '
            . 'returned here; get_cart reads one cart with its money.';
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
                    'customer_email' => ['type' => 'string', 'description' => 'Exact e-mail match.'],
                    'customer_id' => ['type' => 'integer', 'description' => 'Carts of one customer.'],
                    'store_id' => ['type' => 'integer', 'description' => 'Carts on one store view.'],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'True for carts still open — which is what an abandoned '
                            . 'cart looks like. False for carts already placed as orders.',
                    ],
                    'updated_from' => [
                        'type' => 'string',
                        'description' => 'Last touched on or after this date, "YYYY-MM-DD", in UTC '
                            . 'as Magento stores it.',
                    ],
                    'updated_to' => [
                        'type' => 'string',
                        'description' => 'Last touched on or before this date. Combine with '
                            . 'is_active true to find carts abandoned before a given day.',
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
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Cart::cart';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $filtered = false;

        foreach (['customer_email' => 'customer_email', 'store_id' => 'store_id'] as $key => $field) {
            $value = $key === 'store_id'
                ? $this->optionalInt($arguments, $key)
                : $this->optionalString($arguments, $key);
            if ($value !== null) {
                $this->searchCriteriaBuilder->addFilter($field, $value);
                $filtered = true;
            }
        }

        $customerId = $this->optionalInt($arguments, 'customer_id');
        if ($customerId !== null) {
            $this->searchCriteriaBuilder->addFilter('customer_id', $customerId);
            $filtered = true;
        }

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $this->searchCriteriaBuilder->addFilter('is_active', $isActive ? 1 : 0);
            $filtered = true;
        }

        foreach (['updated_from' => 'gteq', 'updated_to' => 'lteq'] as $key => $condition) {
            $value = $this->optionalString($arguments, $key);
            if ($value !== null) {
                $this->searchCriteriaBuilder->addFilter('updated_at', $value, $condition);
                $filtered = true;
            }
        }

        if (!$filtered) {
            throw new LocalizedException(__(
                'Pass at least one filter. An unfiltered lookup returns every quote the store has '
                . 'ever held, most of them empty carts nobody finished.'
            ));
        }

        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'DESC'));
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->setField('updated_at')
                ->setDirection($direction === 'ASC' ? 'ASC' : 'DESC')
                ->create()
        );
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $result = $this->cartRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                fn (CartInterface $cart): array => $this->projector->toSummary($cart),
                array_values($result->getItems())
            ),
        ];
    }
}
