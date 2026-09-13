<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Read one credit memo in full.
 */
class GetCreditMemo extends AbstractTool
{
    /**
     * @param CreditMemoLocator $locator
     * @param CreditMemoProjector $projector
     */
    public function __construct(
        private readonly CreditMemoLocator $locator,
        private readonly CreditMemoProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_credit_memo';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one credit memo by entity_id or its own increment_id: lines, totals and '
            . 'comments. The adjustment totals are what was added to or taken off the refund by '
            . 'hand, and are the usual reason the total is not the sum of the lines. invoice_id is '
            . 'set when the refund went back through the gateway rather than being recorded offline.';
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
        return 'Magento_Sales::sales_creditmemo';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        /** @var CreditmemoInterface $creditmemo */
        $creditmemo = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'entity_id')
        );

        return $this->projector->toDetail($creditmemo);
    }
}
