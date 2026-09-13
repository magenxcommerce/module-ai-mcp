<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Model\AccountManagement;
use Magento\Framework\Exception\LocalizedException;

/**
 * Send a customer the email that lets them set a new password.
 *
 * There is deliberately no tool that sets a password directly. Magento's own
 * service contract for that requires the customer's current password, and
 * writing a chosen password into an account would mean an agent knowing a
 * credential the customer has to trust. Sending the reset email leaves the new
 * password between the store and its customer.
 */
class InitiatePasswordReset extends AbstractTool
{
    /**
     * @param CustomerLocator $locator
     * @param AccountManagementInterface $accountManagement
     */
    public function __construct(
        private readonly CustomerLocator $locator,
        private readonly AccountManagementInterface $accountManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'initiate_password_reset';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Email a customer a link to set a new password, the same action as "Reset Password" '
            . 'in the admin. The customer receives an email immediately, so this is visible to '
            . 'them. It does not change or reveal the current password, and any previously issued '
            . 'reset link stops working.';
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
                    'template' => [
                        'type' => 'string',
                        'enum' => ['reset', 'reminder'],
                        'description' => 'Which email to send. "reset" is the forgot-password email, '
                            . 'the default; "reminder" is the password reminder template.',
                    ],
                ]
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Customer::reset_password';
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
    public function execute(array $arguments): array
    {
        $customer = $this->locator->locate(
            $this->optionalInt($arguments, 'customer_id'),
            $this->optionalString($arguments, 'email'),
            $this->optionalInt($arguments, 'website_id')
        );

        $requested = strtolower((string) $this->optionalString($arguments, 'template', 'reset'));
        if (!in_array($requested, ['reset', 'reminder'], true)) {
            throw new LocalizedException(__('The "template" argument must be "reset" or "reminder".'));
        }
        $template = $requested === 'reminder'
            ? AccountManagement::EMAIL_REMINDER
            : AccountManagement::EMAIL_RESET;

        // The account was located by id or by email within a website; passing
        // its own website id back keeps the email resolving to the same account
        // rather than the default website's.
        $this->accountManagement->initiatePasswordReset(
            (string) $customer->getEmail(),
            $template,
            $customer->getWebsiteId() === null ? null : (int) $customer->getWebsiteId()
        );

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'customer_id' => (int) $customer->getId(),
            'email' => $customer->getEmail(),
            'template' => $requested,
            'email_sent' => true,
        ];
    }
}
