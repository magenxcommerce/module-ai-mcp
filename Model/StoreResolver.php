<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Store;

/**
 * Turns a store code argument into a store id.
 *
 * Getting this wrong is the easiest way to make a silent mistake with this
 * server — writing a store-view value into the default scope, or vice versa —
 * so every scoped tool goes through here and an unknown code is a hard error
 * rather than a fallback to scope 0.
 */
class StoreResolver
{
    /**
     * @param StoreRepositoryInterface $storeRepository
     */
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository
    ) {
    }

    /**
     * Resolve a store code to its id. Null or "admin" means the default scope,
     * which is where a value applies to every store view that has not
     * overridden it.
     *
     * @param string|null $storeCode
     * @return int
     * @throws LocalizedException
     */
    public function resolve(?string $storeCode): int
    {
        if ($storeCode === null || $storeCode === '' || $storeCode === 'admin' || $storeCode === 'default_scope') {
            return Store::DEFAULT_STORE_ID;
        }

        try {
            return (int) $this->storeRepository->get($storeCode)->getId();
        } catch (NoSuchEntityException) {
            throw new LocalizedException(
                __('Unknown store code "%1". Call list_stores to see the available codes.', $storeCode)
            );
        }
    }

    /**
     * Schema fragment for the store argument.
     *
     * @return array<string, mixed>
     */
    public function schemaProperty(): array
    {
        return [
            'type' => 'string',
            'description' => 'Store view code the operation applies to. Omit for the default scope, '
                . 'which every store view inherits unless it has its own override. '
                . 'Use list_stores to discover the codes.',
        ];
    }
}
