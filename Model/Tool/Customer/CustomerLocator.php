<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Turns the customer argument every customer tool accepts into a loaded record.
 *
 * An agent normally holds the email address rather than the numeric id, and in
 * Magento an email identifies an account only together with a website: the same
 * address can be a separate account on each website when accounts are not
 * shared globally. Resolving that here keeps the distinction out of eleven
 * tools, and makes a wrong identifier a tool error rather than an exception.
 */
class CustomerLocator
{
    /**
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * Load the customer named by an id, or by an email within a website.
     *
     * @param int|null $customerId
     * @param string|null $email
     * @param int|null $websiteId
     * @return CustomerInterface
     * @throws LocalizedException
     */
    public function locate(?int $customerId, ?string $email, ?int $websiteId): CustomerInterface
    {
        if ($customerId !== null) {
            try {
                return $this->customerRepository->getById($customerId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('No customer exists with customer_id %1.', $customerId));
            }
        }

        if ($email === null) {
            throw new LocalizedException(__('Pass customer_id, or email to look the customer up by address.'));
        }

        try {
            // A null website id means the default website, which is what
            // Magento itself falls back to; it is the right answer on a
            // single-website installation and wrong to guess at on any other,
            // hence the explicit argument.
            return $this->customerRepository->get($email, $websiteId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException($websiteId === null
                ? __(
                    'No customer account exists for "%1" on the default website. Pass website_id if '
                    . 'the account belongs to another one; list_stores reports the website ids.',
                    $email
                )
                : __('No customer account exists for "%1" on website %2.', $email, $websiteId));
        }
    }

    /**
     * Schema fragment for the arguments that name a customer.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'customer_id' => [
                'type' => 'integer',
                'description' => 'Numeric customer id. Either this or email is required.',
            ],
            'email' => [
                'type' => 'string',
                'description' => 'The account\'s email address. Identifies an account only within '
                    . 'one website, so pass website_id too unless the default website is meant.',
            ],
            'website_id' => [
                'type' => 'integer',
                'description' => 'Website the email belongs to. Omit for the default website. '
                    . 'Ignored when customer_id is given. list_stores reports the website ids.',
            ],
        ];
    }
}
