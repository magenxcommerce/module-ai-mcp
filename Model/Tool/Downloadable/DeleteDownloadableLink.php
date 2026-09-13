<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Downloadable;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Downloadable\Api\LinkRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Remove one downloadable link from a product.
 */
class DeleteDownloadableLink extends AbstractTool
{
    /**
     * @param LinkRepositoryInterface $linkRepository
     * @param ProductTypeLocator $productLocator
     * @param DownloadableProjector $projector
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly ProductTypeLocator $productLocator,
        private readonly DownloadableProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_downloadable_link';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove one downloadable link from a product. Customers who already bought it lose '
            . 'access to that file, and past orders keep the line without anything behind it. '
            . 'Removing the last link leaves the product unbuyable — the reversible alternative is '
            . 'to disable the product.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Sku of the downloadable product.'],
                'link_id' => [
                    'type' => 'integer',
                    'description' => 'Link to remove, as list_downloadable_links reports it.',
                ],
            ],
            'required' => ['sku', 'link_id'],
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
        $linkId = $this->requireInt($arguments, 'link_id');
        $this->productLocator->locate($sku, ListDownloadableLinks::TYPE_DOWNLOADABLE);

        // Read before deleting, so the result names what went and a link_id
        // belonging to another product is refused rather than acted on.
        $removed = null;
        $remaining = 0;
        foreach ($this->linkRepository->getList($sku) as $link) {
            if ((int) $link->getId() === $linkId) {
                $removed = $this->projector->link($link);
                continue;
            }
            $remaining++;
        }

        if ($removed === null) {
            throw new LocalizedException(__(
                'Product "%1" has no downloadable link with link_id %2. list_downloadable_links '
                . 'reports the ids it has.',
                $sku,
                $linkId
            ));
        }

        $this->linkRepository->delete($linkId);

        return [
            'deleted' => true,
            'sku' => $sku,
            'link' => $removed,
            'links_remaining' => $remaining,
        ];
    }
}
