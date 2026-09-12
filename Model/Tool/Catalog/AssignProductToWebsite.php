<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductWebsiteLinkInterfaceFactory;
use Magento\Catalog\Api\ProductWebsiteLinkRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Put a product on a website, or take it off.
 */
class AssignProductToWebsite extends AbstractTool
{
    /**
     * @param ProductWebsiteLinkRepositoryInterface $websiteLinkRepository
     * @param ProductWebsiteLinkInterfaceFactory $websiteLinkFactory
     */
    public function __construct(
        private readonly ProductWebsiteLinkRepositoryInterface $websiteLinkRepository,
        private readonly ProductWebsiteLinkInterfaceFactory $websiteLinkFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'assign_product_to_website';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Put a product on a website or take it off. A product is only sellable on websites '
            . 'it is assigned to, so removing the last one hides it from the storefront entirely '
            . 'while leaving the product intact. get_product reports the current assignments as '
            . 'website_ids, and list_stores maps ids to websites.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Product sku.'],
                'website_id' => [
                    'type' => 'integer',
                    'description' => 'The website; list_stores reports the ids.',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['assign', 'remove'],
                    'description' => 'Defaults to "assign".',
                ],
            ],
            'required' => ['sku', 'website_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Catalog::products';
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
        $sku = $this->requireString($arguments, 'sku');
        $websiteId = $this->requireInt($arguments, 'website_id');
        $action = strtolower((string) $this->optionalString($arguments, 'action', 'assign'));

        if (!in_array($action, ['assign', 'remove'], true)) {
            throw new LocalizedException(__('The "action" argument must be "assign" or "remove".'));
        }

        if ($action === 'remove') {
            $this->websiteLinkRepository->deleteById($sku, $websiteId);
        } else {
            $link = $this->websiteLinkFactory->create();
            $link->setSku($sku);
            $link->setWebsiteId($websiteId);
            $this->websiteLinkRepository->save($link);
        }

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'website_id' => $websiteId,
            'action' => $action,
            'reindex_required' => true,
        ];
    }
}
