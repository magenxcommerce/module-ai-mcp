<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Widget;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Widget\Model\Widget\InstanceFactory;

/**
 * Read one widget instance, including how it is configured and where it is placed.
 */
class GetWidgetInstance extends AbstractTool
{
    /**
     * @param InstanceFactory $instanceFactory
     * @param WidgetInstanceProjector $projector
     */
    public function __construct(
        private readonly InstanceFactory $instanceFactory,
        private readonly WidgetInstanceProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_widget_instance';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one widget by instance_id: its parameters — for a CMS block widget, the block '
            . 'it renders — and its page groups, which say on which pages and in which layout '
            . 'container it appears. Reading only.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'instance_id' => [
                    'type' => 'integer',
                    'description' => 'Widget id, as list_widget_instances reports it.',
                ],
            ],
            'required' => ['instance_id'],
            'additionalProperties' => false,
        ];
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
        $instanceId = $this->requireInt($arguments, 'instance_id');

        // The model loads to an empty object rather than throwing, so a missing
        // id would otherwise come back as a widget with every field null.
        $instance = $this->instanceFactory->create();
        $instance->load($instanceId);
        if (!$instance->getId()) {
            throw new LocalizedException(__('No widget exists with instance_id %1.', $instanceId));
        }

        return $this->projector->toDetail($instance);
    }
}
