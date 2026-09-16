<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\SpamPatternFactory;
use Magenx\Helpdesk\Model\ResourceModel\SpamPattern as ResourceModel;

/**
 * Remove a spam pattern.
 */
class DeleteSpamPattern extends AbstractHelpdeskDelete
{
    /**
     * @param SpamPatternFactory $factory
     * @param ResourceModel $resource
     */
    public function __construct(
        private readonly SpamPatternFactory $factory,
        private readonly ResourceModel $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_helpdesk_spam_pattern';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::spam';
    }

    /**
     * @inheritDoc
     */
    protected function entityNameSingular(): string
    {
        return 'spam pattern';
    }

    /**
     * @inheritDoc
     */
    protected function idArgument(): string
    {
        return 'pattern_id';
    }

    /**
     * @inheritDoc
     */
    protected function orphanWarning(): string
    {
        return 'Mail it was catching starts arriving as tickets again.';
    }

    /**
     * @inheritDoc
     */
    protected function loadRow(int $rowId): ?object
    {
        $row = $this->factory->create();
        $this->resource->load($row, $rowId);

        return $row->getId() ? $row : null;
    }

    /**
     * @inheritDoc
     */
    protected function deleteRow(object $row): void
    {
        $this->resource->delete($row);
    }
}
