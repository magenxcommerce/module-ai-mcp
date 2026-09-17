<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Widget;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Widget\Model\ResourceModel\Widget\Instance\CollectionFactory;
use Magento\Widget\Model\Widget\Instance;

/**
 * List the widget instances configured on this store.
 *
 * Widgets have no `Api/` layer, so this reads the same collection the admin
 * grid does. See the README for what that concrete-class dependency costs.
 */
class ListWidgetInstances extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param WidgetInstanceProjector $projector
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly WidgetInstanceProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_widget_instances';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the widgets configured on this store — title, widget type, theme and which '
            . 'store views each applies to. This is the usual answer to "why is this block '
            . 'appearing on that page": a widget places it, not the page content. Reading only; '
            . 'widgets are created and placed in the admin.';
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
                    'instance_type' => [
                        'type' => 'string',
                        'description' => 'Restrict to one widget type, e.g. '
                            . '"Magento\\\\Cms\\\\Block\\\\Widget\\\\Block". Exact match.',
                    ],
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
        return 'Magento_Widget::widget_instance';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();
        $instanceType = $this->optionalString($arguments, 'instance_type');
        if ($instanceType !== null) {
            $collection->addFieldToFilter('instance_type', $instanceType);
        }
        $collection->setOrder('sort_order', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $instance) {
            /** @var Instance $instance */
            $items[] = $this->projector->toSummary($instance);
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
