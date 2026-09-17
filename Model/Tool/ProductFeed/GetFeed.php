<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one product feed in full, template and all.
 */
class GetFeed extends AbstractTool
{
    /**
     * @param FeedLocator $locator
     * @param FeedProjector $projector
     */
    public function __construct(
        private readonly FeedLocator $locator,
        private readonly FeedProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_feed';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one product feed in full: its template or field map, validation rules, '
            . 'schedule, CSV options, the state of its last run and the public URL of the file '
            . 'it last published — the URL a marketplace fetches. Also reports which of the two '
            . 'rendering modes the feed is actually in, since a feed carrying both a template '
            . 'and a field map uses only the template.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
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

        return $this->projector->toDetail($feed);
    }
}
