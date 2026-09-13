<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\ResourceModel\SpamPattern\CollectionFactory;

/**
 * List the patterns that route inbound mail to Spam.
 */
class ListSpamPatterns extends AbstractHelpdeskList
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_helpdesk_spam_patterns';
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
    protected function entityName(): string
    {
        return 'spam patterns';
    }

    /**
     * @inheritDoc
     */
    protected function orderColumn(): string
    {
        return 'pattern_id';
    }

    /**
     * @inheritDoc
     */
    protected function createCollection(): object
    {
        return $this->collectionFactory->create();
    }

    /**
     * @inheritDoc
     */
    protected function projectRow(object $row): array
    {
        return [
            'pattern_id' => (int) $row->getId(),
            'title' => $row->getData('title'),
            // Which part of an inbound message the pattern is matched against.
            'scope' => $row->getData('scope'),
            'pattern' => $row->getData('pattern'),
            'is_active' => (bool) $row->getData('is_active'),
        ];
    }
}
