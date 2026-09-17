<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\ProductFeed\Model\Delivery;
use Magenx\ProductFeed\Model\ResourceModel\Delivery\CollectionFactory;

/**
 * Report where a feed is delivered, and how the last attempt went.
 *
 * Read-only, and that is the whole decision. A destination row holds the
 * credentials for an FTP account, an SFTP key or a Google service account, so
 * writing them through this server would put a password in a tool call — the
 * same reason the help desk gateways are list-only.
 *
 * Reading them is handled the same way. The module encrypts a value whose key
 * *ends in* `password`, `token`, `secret` or `key`, matching by suffix
 * deliberately so a new deliverer's `api_password` is covered without anybody
 * remembering to add it. This tool redacts by exactly that rule rather than by
 * a list of its own: a list would drift from theirs, and the direction it
 * drifts in is a credential printed into a model's context. What comes back is
 * ciphertext rather than a password, which is not a disclosure — but it is also
 * of no use to anybody, so the key is dropped and named instead.
 *
 * What is left is what an agent actually needs to diagnose a delivery: the
 * destination type, whether it is switched on, when it last ran and what it
 * said.
 */
class ListFeedDeliveries extends AbstractTool
{
    /** A key ending in one of these holds a credential. Copied from DeliveryManager. */
    private const SECRET_SUFFIXES = ['password', 'token', 'secret', 'key'];

    /**
     * @param FeedLocator $locator
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly FeedLocator $locator,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_feed_deliveries';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List where a product feed is delivered — public URL, FTP, SFTP, the Google '
            . 'Merchant API or a push catalog API — with the outcome and message from each '
            . 'destination\'s last attempt. Credentials are never returned: any setting whose '
            . 'name ends in password, token, secret or key is omitted, and is listed by name '
            . 'only. Destinations are configured in the admin, not here.';
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
                    'active_only' => [
                        'type' => 'boolean',
                        'description' => 'Only destinations that are switched on. Defaults to '
                            . 'false, since a destination that was turned off is often the '
                            . 'answer to why a feed is not arriving.',
                    ],
                ]
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_ProductFeed::feed';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $feed = $this->locator->locate(
            $this->optionalInt($arguments, 'feed_id'),
            $this->optionalString($arguments, 'code')
        );

        $collection = $this->collectionFactory->create();
        $collection->addFeedFilter((int) $feed->getFeedId());
        if ($this->optionalBool($arguments, 'active_only', false) === true) {
            $collection->addActiveFilter();
        }

        $items = [];
        foreach ($collection as $delivery) {
            /** @var Delivery $delivery */
            [$settings, $withheld] = $this->split($delivery->getConfigData());

            $items[] = [
                'delivery_id' => (int) $delivery->getId(),
                'type' => $delivery->getType(),
                'is_active' => $delivery->isActive(),
                'last_status' => $delivery->getData('last_status'),
                'last_message' => $delivery->getData('last_message'),
                'last_delivered_at' => $delivery->getData('last_delivered_at'),
                'settings' => $settings,
                'withheld_settings' => $withheld,
            ];
        }

        return [
            'feed_id' => (int) $feed->getFeedId(),
            'code' => $feed->getCode(),
            'items' => $items,
        ];
    }

    /**
     * Separate the readable settings from the credentials.
     *
     * The withheld keys are reported by name. An agent diagnosing "the SFTP
     * delivery fails" needs to know a password is configured at all, and an
     * empty settings block with no explanation reads as a destination that was
     * never set up.
     *
     * @param array<string, mixed> $config
     * @return array{0: array<string, mixed>, 1: string[]}
     */
    private function split(array $config): array
    {
        $settings = [];
        $withheld = [];

        foreach ($config as $key => $value) {
            if ($this->isSecret((string) $key)) {
                $withheld[] = (string) $key;
                continue;
            }
            $settings[$key] = $value;
        }

        return [$settings, $withheld];
    }

    /**
     * @param string $key
     * @return bool
     */
    private function isSecret(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
