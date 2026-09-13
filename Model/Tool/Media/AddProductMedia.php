<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Media;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Add an image to a product.
 *
 * The one tool here that needs actual bytes. Magento's contract takes the image
 * as base64, so this is for a workflow where the image already exists — handed
 * over by a person, or read from somewhere the caller can reach. An agent
 * cannot invent a photograph, and the description says so rather than leaving
 * that to be discovered.
 */
class AddProductMedia extends AbstractTool
{
    /** The roles an image can fill. */
    private const ROLES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    /** What Magento's image validator accepts. */
    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * @param ProductAttributeMediaGalleryManagementInterface $mediaGallery
     * @param ProductAttributeMediaGalleryEntryInterfaceFactory $entryFactory
     * @param ImageContentInterfaceFactory $contentFactory
     * @param MediaEntryProjector $projector
     * @param Base64Payload $payload
     */
    public function __construct(
        private readonly ProductAttributeMediaGalleryManagementInterface $mediaGallery,
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $entryFactory,
        private readonly ImageContentInterfaceFactory $contentFactory,
        private readonly MediaEntryProjector $projector,
        private readonly Base64Payload $payload
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'add_product_media';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add an image to a product. The image has to be supplied as base64 in '
            . 'base64_encoded_data — there is no way to point this at a URL or an existing file, '
            . 'so in practice the bytes come from a person or from somewhere the caller can '
            . 'already read. At most 4 MB decoded, and JPEG, PNG, GIF or WebP. Assigning a role '
            . 'through types takes it away from whichever image currently holds it.';
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
                'base64_encoded_data' => [
                    'type' => 'string',
                    'description' => 'The image file, base64 encoded. Not a URL and not a path.',
                ],
                'mime_type' => [
                    'type' => 'string',
                    'enum' => self::MIME_TYPES,
                    'description' => 'The image\'s media type, which must match the bytes.',
                ],
                'file_name' => [
                    'type' => 'string',
                    'description' => 'File name to store it under, e.g. "blue-shirt-front.jpg". '
                        . 'Magento slugs it and resolves a collision itself.',
                ],
                'label' => ['type' => 'string', 'description' => 'Alt text for the image.'],
                'position' => [
                    'type' => 'integer',
                    'description' => 'Sort position in the gallery, lower first.',
                ],
                'disabled' => [
                    'type' => 'boolean',
                    'description' => 'True adds the image hidden from the storefront. Default false.',
                ],
                'types' => [
                    'type' => 'array',
                    'description' => 'Roles this image should fill. Omit to add it to the gallery '
                        . 'without giving it a role.',
                    'items' => ['type' => 'string', 'enum' => self::ROLES],
                ],
            ],
            'required' => ['sku', 'base64_encoded_data', 'mime_type', 'file_name'],
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
        $fileName = $this->requireString($arguments, 'file_name');
        $mimeType = $this->requireString($arguments, 'mime_type');
        if (!in_array($mimeType, self::MIME_TYPES, true)) {
            throw new LocalizedException(
                __('The "mime_type" argument must be one of: %1.', implode(', ', self::MIME_TYPES))
            );
        }

        $encoded = $this->requireString($arguments, 'base64_encoded_data');
        $this->payload->assertImageMatches(
            $this->payload->decode($encoded, 'base64_encoded_data'),
            'base64_encoded_data',
            $mimeType
        );

        $content = $this->contentFactory->create();
        $content->setBase64EncodedData($encoded);
        $content->setType($mimeType);
        $content->setName($fileName);

        $entry = $this->entryFactory->create();
        $entry->setContent($content);
        $entry->setMediaType('image');
        $entry->setDisabled((bool) $this->optionalBool($arguments, 'disabled', false));

        $label = $this->optionalString($arguments, 'label');
        if ($label !== null) {
            $entry->setLabel($label);
        }
        $position = $this->optionalInt($arguments, 'position');
        if ($position !== null) {
            $entry->setPosition($position);
        }
        $entry->setTypes($this->roles($arguments));

        $entryId = (int) $this->mediaGallery->create($sku, $entry);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'entry' => $this->projector->toArray($this->mediaGallery->get($sku, $entryId)),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function roles(array $arguments): array
    {
        $roles = [];
        foreach ($this->optionalArray($arguments, 'types') as $role) {
            if (!is_string($role) || !in_array($role, self::ROLES, true)) {
                throw new LocalizedException(__(
                    'Every entry in "types" must be one of: %1.',
                    implode(', ', self::ROLES)
                ));
            }
            $roles[] = $role;
        }

        return array_values(array_unique($roles));
    }
}
