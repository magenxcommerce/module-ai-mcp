<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Downloadable;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Downloadable\Api\Data\LinkInterface;
use Magento\Downloadable\Api\LinkRepositoryInterface;

/**
 * Read the files a downloadable product sells.
 */
class ListDownloadableLinks extends AbstractTool
{
    /** Magento's type id for a downloadable product. */
    public const TYPE_DOWNLOADABLE = 'downloadable';

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
        return 'list_downloadable_links';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the downloadable links a product sells: title, price, download limit and '
            . 'whether each is a stored file or a url, with the link_id the save and delete tools '
            . 'take. The file bytes are never returned — only whether a file is set and what it is '
            . 'called. A downloadable product with no links cannot be bought.';
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
            ],
            'required' => ['sku'],
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
    public function execute(array $arguments): array
    {
        $sku = $this->requireString($arguments, 'sku');
        $this->productLocator->locate($sku, self::TYPE_DOWNLOADABLE);

        return [
            'sku' => $sku,
            'links' => array_map(
                fn (LinkInterface $link): array => $this->projector->link($link),
                array_values($this->linkRepository->getList($sku))
            ),
        ];
    }
}
