<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Rma\Api\Data\ItemInterface;
use Magenx\Rma\Api\ItemConditionRepositoryInterface;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Api\RmaItemManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Record the outcome of one returned line.
 *
 * The line is loaded, checked to belong to the return named, and only then
 * saved. That ownership check is not decoration: the management service sets
 * `rma_id` from the argument it is given, so handing it a line belonging to a
 * different return would quietly move that line onto this one rather than
 * failing.
 */
class UpdateRmaItem extends AbstractTool
{
    /**
     * @param RmaLocator $locator
     * @param ItemRepositoryInterface $itemRepository
     * @param RmaItemManagementInterface $itemManagement
     * @param ItemConditionRepositoryInterface $conditionRepository
     */
    public function __construct(
        private readonly RmaLocator $locator,
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly RmaItemManagementInterface $itemManagement,
        private readonly ItemConditionRepositoryInterface $conditionRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_rma_item';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Record the outcome of one line of a return: how much was approved, how much '
            . 'arrived, and what condition it was in. Only the fields you pass are changed. This '
            . 'updates the return\'s own record and moves no money and no stock — refunding is '
            . 'create_credit_memo against the order, and putting stock back is update_stock. Call '
            . 'list_rma_items first for the item ids.';
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
                    'item_id' => [
                        'type' => 'integer',
                        'description' => 'The return line, as list_rma_items reports it. Must '
                            . 'belong to the return named.',
                    ],
                    'qty_approved' => [
                        'type' => ['integer', 'null'],
                        'description' => 'How much of the requested quantity is authorised to come '
                            . 'back. Null clears it.',
                    ],
                    'qty_returned' => [
                        'type' => ['integer', 'null'],
                        'description' => 'How much actually arrived. Null clears it.',
                    ],
                    'condition_id' => [
                        'type' => ['integer', 'null'],
                        'description' => 'Condition the goods arrived in; list_rma_item_conditions '
                            . 'reports the ids. Null clears it.',
                    ],
                ]
            ),
            'required' => ['item_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_manage';
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
    protected function isIdempotent(): bool
    {
        // Only the fields passed are written, to the values passed.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $rma = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'rma_id')
        );
        $rmaId = (int) $rma->getEntityId();
        $itemId = $this->requireInt($arguments, 'item_id');

        try {
            $item = $this->itemRepository->get($itemId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No return line exists with item_id %1.', $itemId));
        }

        if ($item->getRmaId() !== $rmaId) {
            throw new LocalizedException(__(
                'Return line %1 belongs to return %2, not %3. Saving it here would move it onto '
                . 'this return.',
                $itemId,
                $item->getRmaId(),
                $rmaId
            ));
        }

        $changed = $this->apply($item, $arguments);
        if ($changed === []) {
            throw new LocalizedException(__(
                'Nothing to update: pass qty_approved, qty_returned or condition_id.'
            ));
        }

        $saved = $this->itemManagement->save($rmaId, $item);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'rma_id' => $rmaId,
            'increment_id' => $rma->getIncrementId(),
            'item_id' => $saved->getEntityId(),
            'changed_fields' => $changed,
            'order_item_id' => $saved->getOrderItemId(),
            'qty_requested' => $saved->getQtyRequested(),
            'qty_approved' => $saved->getQtyApproved(),
            'qty_returned' => $saved->getQtyReturned(),
            'condition_id' => $saved->getConditionId(),
        ];
    }

    /**
     * @param ItemInterface $item
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function apply(ItemInterface $item, array $arguments): array
    {
        $changed = [];

        foreach (['qty_approved' => 'setQtyApproved', 'qty_returned' => 'setQtyReturned'] as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if ($value === null) {
                $item->{$setter}(null);
                $changed[] = $key;
                continue;
            }
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new LocalizedException(
                    __('The "%1" argument must be a whole number, or null to clear it.', $key)
                );
            }
            if ((int) $value < 0) {
                throw new LocalizedException(__('The "%1" argument cannot be negative.', $key));
            }
            $item->{$setter}((int) $value);
            $changed[] = $key;
        }

        if (array_key_exists('condition_id', $arguments)) {
            $conditionId = $arguments['condition_id'];
            if ($conditionId === null) {
                $item->setConditionId(null);
            } else {
                if (!is_int($conditionId) && !(is_string($conditionId) && ctype_digit($conditionId))) {
                    throw new LocalizedException(
                        __('The "condition_id" argument must be a whole number, or null to clear it.')
                    );
                }
                // A plain foreign key with nothing enforcing it, so an unknown
                // id would store and show as a blank condition.
                try {
                    $this->conditionRepository->get((int) $conditionId);
                } catch (NoSuchEntityException) {
                    throw new LocalizedException(__(
                        'No such condition_id: %1. list_rma_item_conditions reports the ids.',
                        $conditionId
                    ));
                }
                $item->setConditionId((int) $conditionId);
            }
            $changed[] = 'condition_id';
        }

        return $changed;
    }
}
