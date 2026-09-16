<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Media;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface as Entry;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Change an image's label, position, roles or enabled state.
 *
 * Magento's update replaces the gallery entry outright — its implementation
 * assigns the entry it is handed into the existing list by index — so an entry
 * built from only the fields being changed would blank the rest, including the
 * `file` that points at the image on disk. The existing entry is therefore
 * fetched and mutated, never rebuilt.
 */
class UpdateProductMedia extends AbstractTool
{
    /** The roles an image can fill. */
    private const ROLES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    /**
     * @param ProductAttributeMediaGalleryManagementInterface $mediaGallery
     * @param MediaEntryProjector $projector
     */
    public function __construct(
        private readonly ProductAttributeMediaGalleryManagementInterface $mediaGallery,
        private readonly MediaEntryProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_product_media';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change one image\'s label, position, roles or enabled state. Only the fields you '
            . 'pass are changed, and the image file itself is never touched. Assigning a role '
            . 'moves it: a role belongs to one image at a time, so giving "image" to this entry '
            . 'takes it away from whichever entry held it. Passing types as an empty list clears '
            . 'this image\'s roles without giving them to anything else.';
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
                'entry_id' => [
                    'type' => 'integer',
                    'description' => 'Gallery entry id, as list_product_media reports it.',
                ],
                'label' => [
                    'type' => ['string', 'null'],
                    'description' => 'Alt text for the image. Null clears it.',
                ],
                'position' => [
                    'type' => 'integer',
                    'description' => 'Sort position in the gallery, lower first.',
                ],
                'disabled' => [
                    'type' => 'boolean',
                    'description' => 'True hides the image from the storefront without deleting it.',
                ],
                'types' => [
                    'type' => 'array',
                    'description' => 'The roles this image should fill, replacing its current ones. '
                        . 'An empty list clears them.',
                    'items' => ['type' => 'string', 'enum' => self::ROLES],
                ],
            ],
            'required' => ['sku', 'entry_id'],
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
    protected function isIdempotent(): bool
    {
        // Only the fields passed are written, to the values passed.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $sku = $this->requireString($arguments, 'sku');
        $entryId = $this->requireInt($arguments, 'entry_id');

        try {
            $entry = $this->mediaGallery->get($sku, $entryId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'No image with entry id %1 exists on sku "%2". list_product_media reports the ids.',
                $entryId,
                $sku
            ));
        }

        $changed = $this->apply($entry, $arguments);
        if ($changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides sku and entry_id.')
            );
        }

        $this->mediaGallery->update($sku, $entry);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'changed_fields' => $changed,
            'entry' => $this->projector->toArray($this->mediaGallery->get($sku, $entryId)),
        ];
    }

    /**
     * @param Entry $entry
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function apply(Entry $entry, array $arguments): array
    {
        $changed = [];

        if (array_key_exists('label', $arguments)) {
            $label = $arguments['label'];
            if ($label !== null && !is_string($label)) {
                throw new LocalizedException(
                    __('The "label" argument must be a string, or null to clear it.')
                );
            }
            $entry->setLabel($label);
            $changed[] = 'label';
        }

        if (array_key_exists('position', $arguments)) {
            $position = $arguments['position'];
            if (!is_int($position) && !(is_string($position) && ctype_digit($position))) {
                throw new LocalizedException(__('The "position" argument must be a whole number.'));
            }
            $entry->setPosition((int) $position);
            $changed[] = 'position';
        }

        if (array_key_exists('disabled', $arguments)) {
            if (!is_bool($arguments['disabled'])) {
                throw new LocalizedException(__(
                    'The "%1" argument must be true or false, not a string or a number.',
                    'disabled'
                ));
            }
            $entry->setDisabled($arguments['disabled']);
            $changed[] = 'disabled';
        }

        if (array_key_exists('types', $arguments)) {
            $entry->setTypes($this->roles($arguments['types']));
            $changed[] = 'types';
        }

        return $changed;
    }

    /**
     * @param mixed $types
     * @return string[]
     * @throws LocalizedException
     */
    private function roles(mixed $types): array
    {
        if (!is_array($types)) {
            throw new LocalizedException(__(
                'The "types" argument must be a list of roles, or an empty list to clear them.'
            ));
        }

        $roles = [];
        foreach ($types as $role) {
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
