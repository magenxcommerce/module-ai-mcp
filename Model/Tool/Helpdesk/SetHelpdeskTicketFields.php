<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\Field\ValueManager;
use Magenx\Helpdesk\Model\ResourceModel\Field\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Write the custom field values on a ticket.
 *
 * `create_helpdesk_ticket` can set these when a ticket is opened; nothing could
 * change them afterwards, which is the gap here — a field added to the form
 * after a ticket existed had no way of ever being filled in for it.
 *
 * Unknown codes are refused rather than dropped. The underlying store is keyed
 * by field id, so a typo resolves to nothing and saves nothing, and the tool
 * would otherwise report success over a value that was never written.
 */
class SetHelpdeskTicketFields extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param ValueManager $valueManager
     * @param CollectionFactory $fieldCollectionFactory
     * @param TicketProjector $projector
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly ValueManager $valueManager,
        private readonly CollectionFactory $fieldCollectionFactory,
        private readonly TicketProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_helpdesk_ticket_fields';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Set custom field values on an existing ticket, keyed by field code. Only the codes '
            . 'you pass are written; the rest keep their values. list_helpdesk_custom_fields '
            . 'reports the codes, and a code no field answers to is refused rather than silently '
            . 'stored nowhere. The customer is not notified — this changes the record, not the '
            . 'conversation.';
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
                    'custom_fields' => [
                        'type' => 'object',
                        'description' => 'Values keyed by field code, e.g. '
                            . '{"order_reference": "000000123"}. Pass an empty string to clear one.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                ]
            ),
            'required' => ['custom_fields'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::ticket';
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
    protected function isDestructive(): bool
    {
        // Overwrites the values it is given and leaves the rest alone.
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $ticket = $this->locator->locate(
            $this->optionalString($arguments, 'code'),
            $this->optionalInt($arguments, 'ticket_id')
        );

        $values = $arguments['custom_fields'] ?? null;
        if (!is_array($values) || $values === []) {
            throw new LocalizedException(
                __('Pass "custom_fields" as an object of field code to value, with at least one entry.')
            );
        }

        $known = $this->knownCodes();
        $write = [];
        foreach ($values as $code => $value) {
            $code = (string) $code;
            if (!in_array($code, $known, true)) {
                throw new LocalizedException(__(
                    'No custom field has the code "%1". This help desk defines: %2.',
                    $code,
                    $known === [] ? '(none)' : implode(', ', $known)
                ));
            }
            if (!is_string($value)) {
                throw new LocalizedException(
                    __('The value for custom field "%1" must be a string.', $code)
                );
            }
            $write[$code] = $value;
        }

        $this->valueManager->save((int) $ticket->getId(), $write);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'custom_fields' => $write,
        ] + $this->projector->toArray($ticket);
    }

    /**
     * @return string[]
     */
    private function knownCodes(): array
    {
        $codes = [];
        foreach ($this->fieldCollectionFactory->create() as $field) {
            $codes[] = (string) $field->getData('code');
        }

        return $codes;
    }
}
