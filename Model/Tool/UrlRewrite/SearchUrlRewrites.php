<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\UrlRewrite;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Look up URL rewrites.
 *
 * Read-only on purpose. Magento's finder is a contract; writing rewrites is
 * not — `UrlPersistInterface` is the internal mechanism the catalogue uses to
 * regenerate its own rewrites, and driving it from outside is how a store ends
 * up with rewrites that the next product save silently replaces. Custom
 * rewrites belong in the admin, and this tool is for answering why a URL
 * resolves the way it does.
 */
class SearchUrlRewrites extends AbstractTool
{
    /** The finder has no paging, so the cap is enforced here. */
    private const MAX_RESULTS = 100;

    /**
     * @param UrlFinderInterface $urlFinder
     */
    public function __construct(
        private readonly UrlFinderInterface $urlFinder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_url_rewrites';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Look up URL rewrites by request path, target path or the entity they belong to. '
            . 'This is how to answer why a storefront URL 404s or redirects where it does. '
            . 'Read-only: creating and editing rewrites is not exposed, because Magento '
            . 'regenerates entity rewrites on save and would overwrite them.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'request_path' => [
                    'type' => 'string',
                    'description' => 'The incoming path, without a leading slash, e.g. '
                        . '"womens-shoes.html". Exact match.',
                ],
                'target_path' => [
                    'type' => 'string',
                    'description' => 'The path the rewrite resolves to, e.g. "catalog/product/view/id/42".',
                ],
                'entity_type' => [
                    'type' => 'string',
                    'enum' => ['product', 'category', 'cms-page', 'custom'],
                    'description' => 'Restrict to rewrites of one kind of entity.',
                ],
                'entity_id' => [
                    'type' => 'integer',
                    'description' => 'The id of the product, category or page the rewrite points at.',
                ],
                'store_id' => [
                    'type' => 'integer',
                    'description' => 'Restrict to one store view; list_stores reports the ids.',
                ],
                'redirect_type' => [
                    'type' => 'integer',
                    'enum' => [0, 301, 302],
                    'description' => '0 for a rewrite that serves content, 301 or 302 for one that '
                        . 'redirects.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_UrlRewrite::urlrewrite';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $filters = [];

        foreach (['request_path', 'target_path', 'entity_type'] as $field) {
            $value = $this->optionalString($arguments, $field);
            if ($value !== null) {
                $filters[$field] = $value;
            }
        }
        foreach (['entity_id', 'store_id', 'redirect_type'] as $field) {
            $value = $this->optionalInt($arguments, $field);
            if ($value !== null) {
                $filters[$field] = $value;
            }
        }

        if ($filters === []) {
            // An unfiltered lookup would return every rewrite in the store —
            // hundreds of thousands on a real catalogue.
            throw new LocalizedException(__(
                'Pass at least one filter: request_path, target_path, entity_type, entity_id, '
                . 'store_id or redirect_type.'
            ));
        }

        $found = $this->urlFinder->findAllByData($filters);
        $total = count($found);
        $rows = array_slice(array_values($found), 0, self::MAX_RESULTS);

        return [
            'filters' => $filters,
            'total_count' => $total,
            'returned_count' => count($rows),
            'truncated' => $total > count($rows),
            'items' => array_map(
                static fn (UrlRewrite $rewrite): array => [
                    'url_rewrite_id' => (int) $rewrite->getUrlRewriteId(),
                    'request_path' => $rewrite->getRequestPath(),
                    'target_path' => $rewrite->getTargetPath(),
                    'redirect_type' => (int) $rewrite->getRedirectType(),
                    'store_id' => (int) $rewrite->getStoreId(),
                    'entity_type' => $rewrite->getEntityType(),
                    'entity_id' => (int) $rewrite->getEntityId(),
                    // False means an administrator created it by hand, which is
                    // the kind Magento will not regenerate.
                    'is_autogenerated' => (bool) $rewrite->getIsAutogenerated(),
                    'description' => $rewrite->getDescription(),
                ],
                $rows
            ),
        ];
    }
}
