<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attachment;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;

/**
 * Drops the cached pages and GraphQL responses of one product.
 *
 * An attachment is a row in its own table, written beside the product rather
 * than through a product save, so nothing else tells the page cache that the
 * product page changed. Without this, the storefront keeps answering with the
 * attachment list it cached before the write — which reads exactly like the
 * write having failed.
 */
class ProductCacheCleaner
{
    /**
     * @param CacheContext $cacheContext
     * @param ManagerInterface $eventManager
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly CacheContext $cacheContext,
        private readonly ManagerInterface $eventManager,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @param int $productId
     * @return void
     */
    public function clean(int $productId): void
    {
        $tags = [Product::CACHE_TAG . '_' . $productId];

        // The same event a product save ends in: the full page cache and, where
        // configured, Varnish purge on it.
        $this->cacheContext->registerTags($tags);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
        $this->cache->clean($tags);
    }
}
