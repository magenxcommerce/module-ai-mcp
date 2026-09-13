<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Email;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory;
use Magento\Email\Model\Template;

/**
 * List the e-mail templates this store has customised.
 *
 * Only the ones in the database: Magento's defaults live in module files and
 * have no row until someone creates an override from them in the admin.
 */
class ListEmailTemplates extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param EmailTemplateProjector $projector
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly EmailTemplateProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_email_templates';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the store\'s custom e-mail templates with their code, subject and which '
            . 'built-in template each was created from. Only customised templates appear: '
            . 'Magento\'s defaults ship as files and have no record until someone overrides one in '
            . 'the admin, so an empty list means the store sends the stock e-mails. The template '
            . 'body is omitted here — read one with get_email_template.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Email::template';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();
        $collection->setOrder('template_code', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $template) {
            /** @var Template $template */
            $items[] = $this->projector->toSummary($template);
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
