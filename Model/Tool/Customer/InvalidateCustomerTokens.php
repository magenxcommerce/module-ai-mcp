<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Integration\Api\CustomerTokenServiceInterface;

/**
 * Revoke a customer's API access tokens.
 */
class InvalidateCustomerTokens extends AbstractTool
{
    /**
     * @param CustomerLocator $locator
     * @param CustomerTokenServiceInterface $tokenService
     */
    public function __construct(
        private readonly CustomerLocator $locator,
        private readonly CustomerTokenServiceInterface $tokenService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'invalidate_customer_tokens';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Revoke every API access token issued to one customer, signing them out of any app '
            . 'or storefront session holding a token. Useful on a compromised account. It does not '
            . 'change the password, so a caller who knows the password can sign in again and get a '
            . 'fresh token — pair it with initiate_password_reset.';
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
        return 'Magento_Customer::invalidate_tokens';
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

        $this->tokenService->revokeCustomerAccessToken((int) $customer->getId());

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'customer_id' => (int) $customer->getId(),
            'email' => $customer->getEmail(),
            'tokens_revoked' => true,
        ];
    }
}
