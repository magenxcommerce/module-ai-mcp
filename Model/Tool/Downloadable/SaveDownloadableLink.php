<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Downloadable;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Downloadable\Api\Data\LinkInterface;
use Magento\Downloadable\Api\Data\LinkInterfaceFactory;
use Magento\Downloadable\Api\LinkRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Add a downloadable link to a product, or change one.
 *
 * Create-or-update on link_id: omit it to add, pass it to change.
 */
class SaveDownloadableLink extends AbstractTool
{
    /** 0 = never shared, 1 = shared with guests, 2 = follow the store setting. */
    private const SHAREABLE = [0, 1, 2];

    /**
     * @param LinkRepositoryInterface $linkRepository
     * @param LinkInterfaceFactory $linkFactory
     * @param ProductTypeLocator $productLocator
     * @param DownloadableFileArguments $fileArguments
     * @param DownloadableProjector $projector
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly LinkInterfaceFactory $linkFactory,
        private readonly ProductTypeLocator $productLocator,
        private readonly DownloadableFileArguments $fileArguments,
        private readonly DownloadableProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_downloadable_link';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a file or url a downloadable product sells, or change one. Omit link_id to '
            . 'create, pass it to update. A link with link_type "file" stores bytes Magento then '
            . 'serves to anyone who bought the product, so upload only what the store is entitled '
            . 'to distribute; at most 4 MB base64-decoded. number_of_downloads 0 means unlimited. '
            . 'The optional sample_* arguments set this link\'s own free preview, which is not the '
            . 'same thing as the product-level samples save_downloadable_sample manages.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'sku' => ['type' => 'string', 'description' => 'Sku of the downloadable product.'],
                    'link_id' => [
                        'type' => 'integer',
                        'description' => 'Link to change, as list_downloadable_links reports it. '
                            . 'Omit to create a new one.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'What the customer sees. Required when creating.',
                    ],
                    'sort_order' => ['type' => 'integer', 'description' => 'Position in the link list.'],
                    'price' => [
                        'type' => 'number',
                        'description' => 'Charged only when the product is set to buy links '
                            . 'separately; otherwise the product price covers all of them.',
                    ],
                    'number_of_downloads' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'description' => 'How many times a purchaser may download it. 0 means '
                            . 'unlimited.',
                    ],
                    'is_shareable' => [
                        'type' => 'integer',
                        'enum' => self::SHAREABLE,
                        'description' => '0 to require a logged-in purchaser, 1 to let the link be '
                            . 'shared, 2 to follow the store setting.',
                    ],
                ],
                $this->fileArguments->schema('link', 'the file being sold'),
                $this->fileArguments->schema('sample', 'this link\'s free preview')
            ),
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
        $this->productLocator->locate($sku, ListDownloadableLinks::TYPE_DOWNLOADABLE);

        $linkId = $this->optionalInt($arguments, 'link_id');
        $existing = $linkId === null ? null : $this->existingLink($sku, $linkId);
        $isNew = $existing === null;

        $link = $this->linkFactory->create();
        if ($linkId !== null) {
            $link->setId($linkId);
        }

        $title = $this->optionalString($arguments, 'title') ?? $existing?->getTitle();
        if ($title === null || $title === '') {
            throw new LocalizedException(__('The "title" argument is required when creating a link.'));
        }
        $link->setTitle($title);
        $link->setSortOrder(
            $this->optionalInt($arguments, 'sort_order') ?? (int) ($existing?->getSortOrder() ?? 0)
        );
        $link->setPrice($this->price($arguments) ?? (float) ($existing?->getPrice() ?? 0.0));
        $link->setNumberOfDownloads(
            $this->optionalInt($arguments, 'number_of_downloads')
                ?? (int) ($existing?->getNumberOfDownloads() ?? 0)
        );
        $link->setIsShareable($this->shareable($arguments, $existing));

        $linkType = $this->fileArguments->resolveType($arguments, 'link', $existing?->getLinkType())
            ?? DownloadableFileArguments::TYPE_URL;
        $link->setLinkType($linkType);
        $link->setLinkUrl($this->fileArguments->url($arguments, 'link', $linkType, $isNew)
            ?? ($linkType === DownloadableFileArguments::TYPE_URL ? $existing?->getLinkUrl() : null));
        $content = $this->fileArguments->content($arguments, 'link', $linkType, $isNew);
        if ($content !== null) {
            $link->setLinkFileContent($content);
        }

        $sampleType = $this->fileArguments->resolveType($arguments, 'sample', $existing?->getSampleType());
        if ($sampleType !== null) {
            $link->setSampleType($sampleType);
            $link->setSampleUrl($this->fileArguments->url($arguments, 'sample', $sampleType, false)
                ?? ($sampleType === DownloadableFileArguments::TYPE_URL ? $existing?->getSampleUrl() : null));
            $sampleContent = $this->fileArguments->content($arguments, 'sample', $sampleType, false);
            if ($sampleContent !== null) {
                $link->setSampleFileContent($sampleContent);
            }
        }

        $savedId = (int) $this->linkRepository->save($sku, $link);

        return [
            'saved' => true,
            'created' => $isNew,
            'sku' => $sku,
            'link' => $this->projector->link($this->existingLink($sku, $savedId)),
        ];
    }

    /**
     * @param string $sku
     * @param int $linkId
     * @return LinkInterface
     * @throws LocalizedException
     */
    private function existingLink(string $sku, int $linkId): LinkInterface
    {
        foreach ($this->linkRepository->getList($sku) as $link) {
            if ((int) $link->getId() === $linkId) {
                return $link;
            }
        }

        throw new LocalizedException(__(
            'Product "%1" has no downloadable link with link_id %2. list_downloadable_links reports '
            . 'the ids it has.',
            $sku,
            $linkId
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return float|null
     * @throws LocalizedException
     */
    private function price(array $arguments): ?float
    {
        if (!array_key_exists('price', $arguments) || $arguments['price'] === null) {
            return null;
        }

        $price = $arguments['price'];
        if (!is_int($price) && !is_float($price)) {
            throw new LocalizedException(__('The "price" argument must be a number.'));
        }

        return (float) $price;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param LinkInterface|null $existing
     * @return int
     * @throws LocalizedException
     */
    private function shareable(array $arguments, ?LinkInterface $existing): int
    {
        $value = $this->optionalInt($arguments, 'is_shareable');
        if ($value === null) {
            // 2 is Magento's "use the store setting", the safe default for a
            // new link: it inherits whatever the merchant already decided.
            return (int) ($existing?->getIsShareable() ?? 2);
        }

        if (!in_array($value, self::SHAREABLE, true)) {
            throw new LocalizedException(__(
                'The "is_shareable" argument must be 0 (no), 1 (yes) or 2 (use the store setting).'
            ));
        }

        return $value;
    }
}
