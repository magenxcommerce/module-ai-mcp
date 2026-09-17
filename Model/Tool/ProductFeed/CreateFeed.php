<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\ProductFeed\Model\FeedFactory;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * Define a new product feed.
 *
 * `store_id` is required and set only here. A feed's published file lives under
 * its store's own directory, so a feed is bound to one store view for its life;
 * moving one afterwards would orphan the file it already published. Everything
 * a feed resolves — prices, product URLs, attribute values — is resolved in
 * that store's scope too, so this is not a formality.
 *
 * The feed is created with **no conditions**, and unlike a price rule that is
 * the ordinary case rather than a hazard: a feed with no conditions exports the
 * whole catalogue, which is what most feeds are for. Narrowing it to a subset
 * needs the admin's condition widget, which this server does not write.
 */
class CreateFeed extends AbstractTool
{
    /**
     * @param FeedFactory $feedFactory
     * @param FeedResource $feedResource
     * @param FeedArguments $arguments
     * @param FeedProjector $projector
     */
    public function __construct(
        private readonly FeedFactory $feedFactory,
        private readonly FeedResource $feedResource,
        private readonly FeedArguments $arguments,
        private readonly FeedProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_feed';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Define a new product feed. It exports the whole catalogue of its store view — '
            . 'narrowing it to a subset needs the condition widget in the admin, which this '
            . 'server does not write. Choose EITHER a template or a field map, not both: a feed '
            . 'carrying both uses only the template. The code is derived from the name if you do '
            . 'not give one. Nothing is generated until generate_feed is called or cron picks the '
            . 'feed up on its schedule.';
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
                    'store_id' => [
                        'type' => 'integer',
                        'description' => 'Store view the feed belongs to; list_stores reports the '
                            . 'ids. Every value in the feed is resolved in this scope, and it '
                            . 'cannot be changed afterwards.',
                    ],
                    'code' => [
                        'type' => 'string',
                        'description' => 'Unique short handle for the feed. Derived from the name '
                            . 'if omitted.',
                    ],
                ],
                $this->arguments->schemaProperties()
            ),
            'required' => ['name', 'store_id', 'format', 'filename'],
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
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function isDestructive(): bool
    {
        // Adds a feed; touches nothing that already exists, and publishes
        // nothing until it is generated.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $feed = $this->feedFactory->create();

        $storeId = $this->requireInt($arguments, 'store_id');
        $feed->setData('store_id', $storeId);

        $code = $this->optionalString($arguments, 'code');
        if ($code !== null) {
            if ($this->feedResource->getIdByCode($code) !== null) {
                throw new LocalizedException(__(
                    'A feed with code "%1" already exists. Use update_feed to change it, or omit '
                    . 'code to have one derived from the name.',
                    $code
                ));
            }
            $feed->setData('code', $code);
        }

        $this->arguments->applyTo($feed, $arguments, true);

        $this->feedResource->save($feed);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'next_step' => 'The feed is defined but has not run. Call generate_feed to publish '
                . 'it now, or set is_active with a schedule and let cron run it.',
        ] + $this->projector->toDetail($feed);
    }
}
