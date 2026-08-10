<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Store;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store hierarchy, so an agent can scope a change correctly before making
 * it. Every other scoped tool refers the agent here.
 */
class ListStores extends AbstractTool
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_stores';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the websites, store groups and store views of this Magento installation, '
            . 'with the codes other tools accept as their "store_code" argument. '
            . 'Call this before making a change that should apply to one store view only.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $websites = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $groups = [];
            foreach ($website->getGroups() as $group) {
                $stores = [];
                foreach ($group->getStores() as $store) {
                    $stores[] = [
                        'id' => (int) $store->getId(),
                        'code' => $store->getCode(),
                        'name' => $store->getName(),
                        'is_active' => (bool) $store->getIsActive(),
                    ];
                }
                $groups[] = [
                    'id' => (int) $group->getId(),
                    'code' => $group->getCode(),
                    'name' => $group->getName(),
                    'root_category_id' => (int) $group->getRootCategoryId(),
                    'store_views' => $stores,
                ];
            }
            $websites[] = [
                'id' => (int) $website->getId(),
                'code' => $website->getCode(),
                'name' => $website->getName(),
                'is_default' => (bool) $website->getIsDefault(),
                'groups' => $groups,
            ];
        }

        return [
            'default_scope_note' => 'Omit store_code (or pass "admin") to read or write the default '
                . 'scope, which all store views inherit unless overridden.',
            'websites' => $websites,
        ];
    }
}
