<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Design;

use Magenx\AiMcp\Model\StoreResolver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * Turns the scope arguments the design tools take into what the repository wants.
 *
 * Design configuration is scoped the way store configuration is — a default
 * that everything inherits, an override per website, an override per store view
 * — but its repository is addressed by scope name and id rather than by a store
 * code. The same mistake is available here as everywhere else in this server:
 * writing the default when a store view was meant, or the reverse.
 */
class DesignConfigScope
{
    public const SCOPE_DEFAULT = 'default';
    public const SCOPE_WEBSITE = 'websites';
    public const SCOPE_STORE = 'stores';

    /**
     * @param StoreResolver $storeResolver
     * @param WebsiteRepositoryInterface $websiteRepository
     */
    public function __construct(
        private readonly StoreResolver $storeResolver,
        private readonly WebsiteRepositoryInterface $websiteRepository
    ) {
    }

    /**
     * Schema fragment for the two scope arguments.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'store_code' => [
                'type' => 'string',
                'description' => 'Store view code to read or write the override for. Omit both this '
                    . 'and website_code for the default scope, which every website and store view '
                    . 'inherits unless it has its own override. list_stores reports the codes.',
            ],
            'website_code' => [
                'type' => 'string',
                'description' => 'Website code, for a website-level override. Cannot be combined '
                    . 'with store_code.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: string, 1: int} Scope name and scope id.
     * @throws LocalizedException
     */
    public function resolve(array $arguments): array
    {
        $storeCode = $arguments['store_code'] ?? null;
        $websiteCode = $arguments['website_code'] ?? null;
        $storeCode = is_string($storeCode) && trim($storeCode) !== '' ? trim($storeCode) : null;
        $websiteCode = is_string($websiteCode) && trim($websiteCode) !== '' ? trim($websiteCode) : null;

        if ($storeCode !== null && $websiteCode !== null) {
            throw new LocalizedException(__(
                'Pass store_code or website_code, not both — they name different scopes.'
            ));
        }

        if ($storeCode !== null) {
            return [self::SCOPE_STORE, $this->storeResolver->resolve($storeCode)];
        }

        if ($websiteCode !== null) {
            try {
                return [self::SCOPE_WEBSITE, (int) $this->websiteRepository->get($websiteCode)->getId()];
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__(
                    'Unknown website code "%1". Call list_stores to see the available codes.',
                    $websiteCode
                ));
            }
        }

        return [self::SCOPE_DEFAULT, 0];
    }
}
