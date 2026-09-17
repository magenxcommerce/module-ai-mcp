<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\Store;

/**
 * Start a cart.
 *
 * **`store_code` is required, and that is the whole reason this tool is not two
 * lines.** `CartManagementInterface::createEmptyCart()` takes no store at all —
 * Magento resolves one from ambient scope. In a storefront request that is the
 * store the customer is browsing; in a headless request arriving at this
 * endpoint it is whatever scope the controller happens to be in, which is not a
 * storefront and was never chosen with selling in mind.
 *
 * The store decides the cart's currency, its prices, its tax rules and which
 * shipping and payment methods exist. Getting it from ambient scope means every
 * one of those is silently whatever the default was — an order priced in the
 * wrong currency looks exactly like an order priced in the right one until
 * somebody reconciles the books. So the caller says which storefront it is
 * selling on, and a cart is never created in the admin scope.
 *
 * A cart created for a customer is that customer's cart; one created without
 * `customer_id` is a guest cart, and needs an e-mail on its billing address
 * before it can be placed.
 */
class CreateCart extends AbstractTool
{
    /**
     * @param CartManagementInterface $cartManagement
     * @param CartRepositoryInterface $cartRepository
     * @param StoreResolver $storeResolver
     * @param CartProjector $projector
     */
    public function __construct(
        private readonly CartManagementInterface $cartManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly StoreResolver $storeResolver,
        private readonly CartProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_cart';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Start a new shopping cart on a named store view. The store view is required and '
            . 'decides the cart\'s currency, prices, tax and which shipping and payment methods '
            . 'exist, so it cannot be guessed — use list_stores for the codes. Pass customer_id to '
            . 'build the cart for an existing customer; omit it for a guest cart, which will need '
            . 'an e-mail address on its billing address before it can be placed. Nothing is '
            . 'charged or reserved until place_order.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'store_code' => array_merge(
                    $this->storeResolver->schemaProperty(),
                    ['description' => 'Store view the cart belongs to. Required — it sets the '
                        . 'currency, prices and tax the whole order is built on. list_stores '
                        . 'reports the codes.']
                ),
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Build the cart for this customer. Omit for a guest cart.',
                ],
            ],
            'required' => ['store_code'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Cart::manage';
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
        // Creates an empty cart; nothing that already exists is touched, and
        // nothing is charged or reserved.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $storeId = $this->resolveSellingStore($arguments);
        $customerId = $this->optionalInt($arguments, 'customer_id');

        $cartId = $customerId === null
            ? (int) $this->cartManagement->createEmptyCart()
            : (int) $this->cartManagement->createEmptyCartForCustomer($customerId);

        // createEmptyCart() took no store, so the quote currently belongs to
        // whatever scope this request is in. Set the one that was asked for and
        // save it before anything is priced against the wrong storefront.
        $cart = $this->cartRepository->get($cartId);
        $cart->setStoreId($storeId);
        $this->cartRepository->save($cart);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'next_step' => 'Add products with add_cart_item, then estimate_cart_shipping and '
                . 'set_cart_delivery, then set_cart_payment_method, then place_order.',
        ] + $this->projector->toDetail($this->cartRepository->get($cartId));
    }

    /**
     * Resolve the store, and refuse the one scope a cart cannot live in.
     *
     * @param array<string, mixed> $arguments
     * @return int
     * @throws LocalizedException
     */
    private function resolveSellingStore(array $arguments): int
    {
        $storeCode = $this->requireString($arguments, 'store_code');
        $storeId = $this->storeResolver->resolve($storeCode);

        if ($storeId === Store::DEFAULT_STORE_ID) {
            // StoreResolver maps the admin scope to 0, which is right for a
            // configuration value and wrong for a cart: scope 0 is not a
            // storefront, so it has no currency, no catalogue prices and no
            // shipping methods. A cart there would price everything at nothing.
            throw new LocalizedException(__(
                'A cart cannot be created in the admin scope — it is not a storefront, so it has '
                . 'no currency, prices or shipping methods. Pass the code of a real store view; '
                . 'list_stores reports them.'
            ));
        }

        return $storeId;
    }
}
