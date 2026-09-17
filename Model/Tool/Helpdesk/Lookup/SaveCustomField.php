<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\FieldFactory;
use Magenx\Helpdesk\Model\ResourceModel\Field as ResourceModel;

/**
 * Create or edit a ticket custom field.
 *
 * The field's `code` is the key `create_helpdesk_ticket` takes in its
 * `custom_fields` argument, so renaming one silently breaks every caller that
 * was passing the old name — the value simply stops being stored.
 */
class SaveCustomField extends AbstractHelpdeskSave
{
    /**
     * @param FieldFactory $factory
     * @param ResourceModel $resource
     */
    public function __construct(
        private readonly FieldFactory $factory,
        private readonly ResourceModel $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_helpdesk_custom_field';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::field';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'custom fields';
    }

    /**
     * @inheritDoc
     */
    protected function entityNameSingular(): string
    {
        return 'custom field';
    }

    /**
     * @inheritDoc
     */
    protected function idArgument(): string
    {
        return 'field_id';
    }

    /**
     * @inheritDoc
     */
    protected function fields(): array
    {
        return [
            'code' => [
                'type' => self::TYPE_STRING,
                'required' => true,
                'description' => 'Machine name. This is the key create_helpdesk_ticket and '
                    . 'set_helpdesk_ticket_fields take, so renaming it breaks callers using the '
                    . 'old one.',
            ],
            'title' => [
                'type' => self::TYPE_STRING,
                'required' => true,
                'description' => 'Label shown beside the input.',
            ],
            'type' => [
                'type' => self::TYPE_STRING,
                'enum' => ['text', 'textarea', 'select', 'multiselect', 'checkbox', 'date'],
                'description' => 'Input kind. "select" and "multiselect" need options.',
            ],
            'options' => [
                'type' => self::TYPE_STRING,
                'description' => 'Choices for select and multiselect, as the module stores them — '
                    . 'read an existing field with list_helpdesk_custom_fields to see the format '
                    . 'before writing one.',
            ],
            'description' => [
                'type' => self::TYPE_STRING,
                'description' => 'Hint shown under the input.',
            ],
            'sort_order' => [
                'type' => self::TYPE_INT,
                'description' => 'Position in the form, lower first.',
            ],
            'is_active' => [
                'type' => self::TYPE_BOOL,
                'description' => 'Whether the field is in use at all.',
            ],
            'is_visible_customer' => [
                'type' => self::TYPE_BOOL,
                'description' => 'Whether the customer sees it.',
            ],
            'is_editable_customer' => [
                'type' => self::TYPE_BOOL,
                'description' => 'Whether the customer can change it.',
            ],
            'is_required_customer' => [
                'type' => self::TYPE_BOOL,
                'description' => 'Whether the customer must fill it in.',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function newRow(): object
    {
        return $this->factory->create();
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
    protected function saveRow(object $row): void
    {
        $this->resource->save($row);
    }

    /**
     * @inheritDoc
     */
    protected function projectRow(object $row): array
    {
        return [
            'field_id' => (int) $row->getId(),
            'code' => $row->getData('code'),
            'title' => $row->getData('title'),
            'type' => $row->getData('type'),
            'options' => $row->getData('options'),
            'description' => $row->getData('description'),
            'is_active' => (bool) $row->getData('is_active'),
            'is_visible_customer' => (bool) $row->getData('is_visible_customer'),
            'is_editable_customer' => (bool) $row->getData('is_editable_customer'),
            'is_required_customer' => (bool) $row->getData('is_required_customer'),
            'sort_order' => (int) $row->getData('sort_order'),
        ];
    }
}
