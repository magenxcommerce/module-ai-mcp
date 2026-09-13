<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Link;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductLinkManagementInterface;
use Magento\Catalog\Api\ProductLinkTypeListInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Replace a product's links of one type.
 *
 * `setProductLinks` replaces rather than merges, and only for the types present
 * in what it is given — so a call carrying only `related` items leaves `upsell`
 * alone but wipes any related link not listed. That is easy to read as "add
 * these", which is why this tool takes one type at a time, says so, and offers
 * to read the current list into the new one.
 */
class SetProductLinks extends AbstractTool
{
    /**
     * @param ProductLinkManagementInterface $linkManagement
     * @param ProductLinkTypeListInterface $linkTypeList
     * @param ProductLinkInterfaceFactory $linkFactory
     */
    public function __construct(
        private readonly ProductLinkManagementInterface $linkManagement,
        private readonly ProductLinkTypeListInterface $linkTypeList,
        private readonly ProductLinkInterfaceFactory $linkFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_product_links';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Set a product\'s linked products for one link type. This replaces the whole list '
            . 'for that type: any existing link of the type that is not in what you pass is '
            . 'removed. Set mode to "append" to have the current links read and kept, which is '
            . 'what you want to add one product to an existing set. Other link types are never '
            . 'touched. To remove a single link, delete_product_link is simpler.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'The product the links belong to.'],
                'link_type' => [
                    'type' => 'string',
                    'description' => 'Link type name, e.g. "related", "upsell", "crosssell" or '
                        . '"associated" — the "name" from list_product_link_types.',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['replace', 'append'],
                    'description' => '"replace" makes the given list the complete set for this '
                        . 'type, removing anything not listed. "append" keeps the links the '
                        . 'product already has. Defaults to "replace", which is what Magento\'s '
                        . 'own operation does.',
                ],
                'linked_products' => [
                    'type' => 'array',
                    'description' => 'The products to link to.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string', 'description' => 'The linked product\'s sku.'],
                            'position' => [
                                'type' => 'integer',
                                'description' => 'Sort position within the list, lower first.',
                            ],
                        ],
                        'required' => ['sku'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['sku', 'link_type', 'linked_products'],
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
        $linkType = $this->assertKnownLinkType($this->requireString($arguments, 'link_type'));

        $mode = strtolower((string) $this->optionalString($arguments, 'mode', 'replace'));
        if (!in_array($mode, ['replace', 'append'], true)) {
            throw new LocalizedException(__('The "mode" argument must be "replace" or "append".'));
        }

        $requested = $this->requestedLinks($arguments, $sku, $linkType);

        $existing = [];
        if ($mode === 'append') {
            try {
                $existing = $this->linkManagement->getLinkedItemsByType($sku, $linkType);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('No product exists with sku "%1".', $sku));
            }
        }

        // A requested link wins over the existing one of the same sku, so
        // appending can also restate a position.
        $merged = [];
        foreach ($existing as $link) {
            $merged[strtolower((string) $link->getLinkedProductSku())] = $link;
        }
        foreach ($requested as $link) {
            $merged[strtolower((string) $link->getLinkedProductSku())] = $link;
        }
        $links = array_values($merged);

        try {
            $this->linkManagement->setProductLinks($sku, $links);
        } catch (NoSuchEntityException $e) {
            // Raised for the parent sku and for any linked sku that does not
            // exist; Magento's message names which.
            throw new LocalizedException(__($e->getMessage()));
        }

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'link_type' => $linkType,
            'mode' => $mode,
            'linked_skus' => array_map(
                static fn (ProductLinkInterface $link): string => (string) $link->getLinkedProductSku(),
                $links
            ),
            'total_count' => count($links),
        ];
    }

    /**
     * Build the link objects the caller asked for.
     *
     * @param array<string, mixed> $arguments
     * @param string $sku
     * @param string $linkType
     * @return ProductLinkInterface[]
     * @throws LocalizedException
     */
    private function requestedLinks(array $arguments, string $sku, string $linkType): array
    {
        $rows = $this->optionalArray($arguments, 'linked_products');
        if ($rows === []) {
            throw new LocalizedException(__(
                'Pass at least one entry in "linked_products". To remove every link of this type, '
                . 'use delete_product_link for each one.'
            ));
        }

        $links = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $position = (int) $index + 1;
            if (!is_array($row)) {
                throw new LocalizedException(__('Entry %1 must be an object with a sku.', $position));
            }

            $linkedSku = $row['sku'] ?? null;
            if (!is_string($linkedSku) || trim($linkedSku) === '') {
                throw new LocalizedException(__('Entry %1 needs a "sku".', $position));
            }
            $linkedSku = trim($linkedSku);

            if (strcasecmp($linkedSku, $sku) === 0) {
                throw new LocalizedException(
                    __('Entry %1 links "%2" to itself.', $position, $linkedSku)
                );
            }

            $key = strtolower($linkedSku);
            if (isset($seen[$key])) {
                throw new LocalizedException(__(
                    'Entries %1 and %2 both name sku "%3". List it once.',
                    $seen[$key],
                    $position,
                    $linkedSku
                ));
            }
            $seen[$key] = $position;

            $link = $this->linkFactory->create();
            $link->setSku($sku);
            $link->setLinkType($linkType);
            $link->setLinkedProductSku($linkedSku);

            $linkPosition = $row['position'] ?? null;
            if ($linkPosition !== null) {
                if (!is_int($linkPosition) && !(is_string($linkPosition) && ctype_digit($linkPosition))) {
                    throw new LocalizedException(
                        __('Entry %1 has an unusable "position"; it must be a whole number.', $position)
                    );
                }
                $link->setPosition((int) $linkPosition);
            }

            $links[] = $link;
        }

        return $links;
    }

    /**
     * @param string $linkType
     * @return string
     * @throws LocalizedException
     */
    private function assertKnownLinkType(string $linkType): string
    {
        $names = [];
        foreach ($this->linkTypeList->getItems() as $type) {
            $names[] = (string) $type->getName();
        }

        if (!in_array($linkType, $names, true)) {
            throw new LocalizedException(__(
                'Unknown link type "%1". This installation has: %2.',
                $linkType,
                implode(', ', $names)
            ));
        }

        return $linkType;
    }
}
