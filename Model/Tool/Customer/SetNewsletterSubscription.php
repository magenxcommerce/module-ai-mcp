<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Subscribe or unsubscribe a customer from the newsletter.
 */
class SetNewsletterSubscription extends AbstractTool
{
    /**
     * @param CustomerLocator $locator
     * @param SubscriptionManagerInterface $subscriptionManager
     * @param StoreRepositoryInterface $storeRepository
     */
    public function __construct(
        private readonly CustomerLocator $locator,
        private readonly SubscriptionManagerInterface $subscriptionManager,
        private readonly StoreRepositoryInterface $storeRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_newsletter_subscription';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Subscribe or unsubscribe a customer from the newsletter. Subscribing can send a '
            . 'confirmation email and, where the store requires opt-in confirmation, leaves the '
            . 'subscription unconfirmed until the customer follows it — the returned status says '
            . 'which. Treat a subscribe as needing the customer\'s own consent; unsubscribing on '
            . 'their behalf is always safe.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                [
                    'subscribed' => [
                        'type' => 'boolean',
                        'description' => 'True to subscribe, false to unsubscribe.',
                    ],
                    'store_id' => [
                        'type' => 'integer',
                        'description' => 'Store view the subscription belongs to. Defaults to the '
                            . 'customer\'s own store. list_stores reports the ids.',
                    ],
                ]
            ),
            'required' => ['subscribed'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Newsletter::subscriber';
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
    public function execute(array $arguments): array
    {
        $customer = $this->locator->locate(
            $this->optionalInt($arguments, 'customer_id'),
            $this->optionalString($arguments, 'email'),
            $this->optionalInt($arguments, 'website_id')
        );

        $subscribed = $this->optionalBool($arguments, 'subscribed');
        if ($subscribed === null) {
            throw new LocalizedException(
                __('The "subscribed" argument is required: true to subscribe, false to unsubscribe.')
            );
        }

        $customerId = (int) $customer->getId();
        $storeId = $this->resolveStoreId($arguments, $customer->getStoreId());

        $subscriber = $subscribed
            ? $this->subscriptionManager->subscribeCustomer($customerId, $storeId)
            : $this->subscriptionManager->unsubscribeCustomer($customerId, $storeId);

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'customer_id' => $customerId,
            'email' => $customer->getEmail(),
            'store_id' => $storeId,
            'is_subscribed' => (bool) $subscriber->isSubscribed(),
            'status' => $this->statusLabel((int) $subscriber->getStatus()),
        ];
    }

    /**
     * The store the subscription is recorded against.
     *
     * A subscription is per store view, and store id 0 is not a store a
     * customer can be subscribed on, so an account that has never been scoped
     * to one has to be given a store explicitly rather than silently recorded
     * against the admin scope.
     *
     * @param array<string, mixed> $arguments
     * @param int|string|null $customerStoreId
     * @return int
     * @throws LocalizedException
     */
    private function resolveStoreId(array $arguments, int|string|null $customerStoreId): int
    {
        $storeId = $this->optionalInt($arguments, 'store_id') ?? (int) $customerStoreId;
        if ($storeId === 0) {
            throw new LocalizedException(__(
                'This account is not associated with a store view, so the subscription has no store '
                . 'to belong to. Pass store_id; list_stores reports the ids.'
            ));
        }

        // A store id that does not exist would otherwise be written into the
        // subscriber row and only fail later, when an email is sent.
        try {
            $this->storeRepository->getById($storeId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(
                __('Unknown store_id %1. Call list_stores to see the available stores.', $storeId)
            );
        }

        return $storeId;
    }

    /**
     * @param int $status
     * @return string
     */
    private function statusLabel(int $status): string
    {
        return match ($status) {
            Subscriber::STATUS_SUBSCRIBED => 'subscribed',
            Subscriber::STATUS_NOT_ACTIVE => 'not_active',
            Subscriber::STATUS_UNSUBSCRIBED => 'unsubscribed',
            Subscriber::STATUS_UNCONFIRMED => 'unconfirmed',
            default => 'unknown',
        };
    }
}
