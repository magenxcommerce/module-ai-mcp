<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\AdminActivity;

use Magenx\AdminActivity\Model\ActivityDetail;
use Magenx\AdminActivity\Model\ActivityFactory;
use Magenx\AdminActivity\Model\ResourceModel\ActivityDetail\CollectionFactory as DetailCollectionFactory;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * One admin action, with the fields it changed.
 *
 * The before/after values are the reason this tool exists: they turn "someone
 * edited this product" into "the price went from 19.99 to 9.99 at 14:02", which
 * is the only form of the answer anyone can act on.
 */
class GetAdminActivity extends AbstractTool
{
    /**
     * @param ActivityFactory $activityFactory
     * @param DetailCollectionFactory $detailCollectionFactory
     * @param ActivityProjector $projector
     */
    public function __construct(
        private readonly ActivityFactory $activityFactory,
        private readonly DetailCollectionFactory $detailCollectionFactory,
        private readonly ActivityProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_admin_activity';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one admin action in full, including the before and after value of every field '
            . 'it changed, the request URL and the user agent. Find the activity_id with '
            . 'search_admin_activity. Long values are truncated, and say so where they are.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'activity_id' => [
                    'type' => 'integer',
                    'description' => 'The id reported by search_admin_activity.',
                ],
            ],
            'required' => ['activity_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_AdminActivity::activity';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $activityId = $this->requireInt($arguments, 'activity_id');

        $activity = $this->activityFactory->create();
        $activity->load($activityId);
        if (!$activity->getData('activity_id')) {
            throw new LocalizedException(
                __('No admin activity exists with activity_id %1.', $activityId)
            );
        }

        $details = $this->detailCollectionFactory->create()->addActivityFilter($activityId);

        $rows = [];
        foreach ($details as $detail) {
            /** @var ActivityDetail $detail */
            $rows[] = $detail;
        }

        return $this->projector->toDetail($activity, $rows);
    }
}
