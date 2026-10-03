<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\QuickSearchGraphQl\Model\Promotion;
use Magenx\QuickSearchGraphQl\Model\Source\Type;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Applies the writable fields of a quick search promotion, with the admin's
 * checks and a few it lacks.
 *
 * The quick search module validates in `Controller/Adminhtml/Promotion/Save.php`,
 * not in the model or the repository, so a tool that set fields and saved would
 * get none of it. Everything that controller checks is repeated here — a known
 * type, a non-empty target, a product SKU that exists, a numeric category or
 * brand id, `active_to` not before `active_from` — and three things are added,
 * each because the failure is silent on the storefront:
 *
 *  - **The target must exist**, for categories and brands too
 *    ({@see TargetValidator}). The dropdown drops a promotion whose target
 *    does not resolve, without a word.
 *  - **The store view must exist.** The admin offers a select; here an id is
 *    typed, and a wrong one would fail on the foreign key as a database error.
 *  - **The image must be a file in `pub/media`.** The storefront loads every
 *    image through its `/media` proxy, so an image hosted elsewhere never
 *    loads, and a path to nothing renders a broken image.
 *
 * Dates are `YYYY-MM-DD` and nothing else. The admin parses them in the admin
 * user's locale; there is no locale here, and "03/04/2026" means two different
 * days depending on where it was written.
 *
 * Keywords are an array, joined into the comma-separated column the module
 * reads. A comma inside one keyword would silently become two, so it is
 * refused rather than split.
 */
class PromotionArguments
{
    /** Column widths, so an overlong value is refused rather than truncated. */
    private const MAX_LENGTH = ['target' => 255, 'title' => 255, 'image' => 512];

    /**
     * @param TargetValidator $targetValidator
     * @param StoreManagerInterface $storeManager
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly TargetValidator $targetValidator,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * Set the fields present in the arguments, then check the resulting row.
     *
     * @param Promotion $promotion
     * @param array<string, mixed> $arguments
     * @param bool $isCreate
     * @return string[] The field names actually set.
     * @throws LocalizedException
     */
    public function applyTo(Promotion $promotion, array $arguments, bool $isCreate): array
    {
        $changed = array_merge(
            $this->applyTypeAndTarget($promotion, $arguments, $isCreate),
            $this->applyStore($promotion, $arguments, $isCreate),
            $this->applyText($promotion, $arguments),
            $this->applyImage($promotion, $arguments),
            $this->applyKeywords($promotion, $arguments),
            $this->applyDates($promotion, $arguments),
            $this->applyNumbers($promotion, $arguments, $isCreate)
        );

        // Checked on the resulting row rather than the arguments, so that moving
        // only active_to before an active_from set last month is caught too.
        $from = $promotion->getData('active_from');
        $to = $promotion->getData('active_to');
        if ($from && $to && (string) $from > (string) $to) {
            throw new LocalizedException(__(
                'active_to (%1) must not be before active_from (%2).',
                $to,
                $from
            ));
        }

        return $changed;
    }

    /**
     * The properties create and update share.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'type' => [
                'type' => 'string',
                'enum' => [Type::PRODUCT, Type::CATEGORY, Type::BRAND],
                'description' => 'What the promotion points at. Decides how target is read.',
            ],
            'target' => [
                'type' => 'string',
                'description' => 'For product, the SKU. For category, the category id. For '
                    . 'brand, the option id of the "manufacturer" attribute (get_product_attribute '
                    . 'lists them). Must exist.',
            ],
            'store_id' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'Store view to show it in, or 0 for every store view (the '
                    . 'default). list_stores reports the ids.',
            ],
            'title' => [
                'type' => ['string', 'null'],
                'description' => 'Overrides the product, category or brand name in the dropdown. '
                    . 'Null or "" clears it.',
            ],
            'image' => [
                'type' => ['string', 'null'],
                'description' => 'Overrides the image, as a path under pub/media such as '
                    . '"wysiwyg/promos/summer.jpg" — the path upload_media_gallery_asset returns. '
                    . 'The file must exist. URLs are refused: the storefront loads images only '
                    . 'from this store\'s media. Null or "" clears it, which falls back to the '
                    . 'category image or the brand\'s image swatch.',
            ],
            'keywords' => [
                'type' => ['array', 'null'],
                'items' => ['type' => 'string'],
                'description' => 'Search terms that show this promotion below the results. A '
                    . 'search matches when it contains a keyword, or a keyword starts with it, '
                    . 'case-insensitively. Empty or null makes it a general promotion, shown '
                    . 'when the search box is opened empty and after every search. No commas '
                    . 'inside a keyword.',
            ],
            'active_from' => [
                'type' => ['string', 'null'],
                'format' => 'date',
                'description' => 'First day it shows, as YYYY-MM-DD in the store view\'s '
                    . 'timezone. Null for no start date.',
            ],
            'active_to' => [
                'type' => ['string', 'null'],
                'format' => 'date',
                'description' => 'Last day it shows (inclusive), as YYYY-MM-DD. Null for no end '
                    . 'date.',
            ],
            'sort_order' => [
                'type' => 'integer',
                'description' => 'Lower shows first. Also decides which promotions survive the '
                    . 'Maximum Items cap.',
            ],
            'is_active' => [
                'type' => 'boolean',
                'description' => 'Whether it can show at all. Defaults to true on create.',
            ],
        ];
    }

    /**
     * @param Promotion $promotion
     * @param array<string, mixed> $arguments
     * @param bool $isCreate
     * @return string[]
     * @throws LocalizedException
     */
    private function applyTypeAndTarget(Promotion $promotion, array $arguments, bool $isCreate): array
    {
        $changed = [];

        if (array_key_exists('type', $arguments)) {
            $type = $arguments['type'];
            if (!in_array($type, [Type::PRODUCT, Type::CATEGORY, Type::BRAND], true)) {
                throw new LocalizedException(__(
                    'The "type" argument must be one of "%1", "%2" or "%3".',
                    Type::PRODUCT,
                    Type::CATEGORY,
                    Type::BRAND
                ));
            }
            $promotion->setData('type', $type);
            $changed[] = 'type';
        }

        if (array_key_exists('target', $arguments)) {
            $target = $arguments['target'];
            if (!is_string($target) || trim($target) === '') {
                throw new LocalizedException(__('The "target" argument must be a non-empty string.'));
            }
            $target = trim($target);
            $this->assertLength('target', $target);
            $promotion->setData('target', $target);
            $changed[] = 'target';
        }

        if ($isCreate) {
            foreach (['type', 'target'] as $key) {
                if (!in_array($key, $changed, true)) {
                    throw new LocalizedException(__('The "%1" argument is required.', $key));
                }
            }
        }

        // A changed type re-reads an unchanged target, so either one changing
        // means the pair is checked again.
        if ($changed !== []) {
            $type = (string) $promotion->getData('type');
            $target = (string) $promotion->getData('target');
            if ($type !== Type::PRODUCT && !ctype_digit($target)) {
                throw new LocalizedException(__(
                    'The target of a %1 promotion must be a numeric id, not "%2".',
                    $type,
                    $target
                ));
            }
            $this->targetValidator->assertExists($type, $target);
        }

        return $changed;
    }

    /**
     * @param Promotion $promotion
     * @param array<string, mixed> $arguments
     * @param bool $isCreate
     * @return string[]
     * @throws LocalizedException
     */
    private function applyStore(Promotion $promotion, array $arguments, bool $isCreate): array
    {
        if (!array_key_exists('store_id', $arguments)) {
            if ($isCreate) {
                $promotion->setData('store_id', 0);
            }

            return [];
        }

        $storeId = $arguments['store_id'];
        if (!is_int($storeId) || $storeId < 0) {
            throw new LocalizedException(__('The "store_id" argument must be 0 or a store view id.'));
        }

        if ($storeId !== 0) {
            try {
                $this->storeManager->getStore($storeId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__(
                    'No store view exists with id %1. Use list_stores to see them, or pass 0 for '
                    . 'every store view.',
                    $storeId
                ));
            }
        }

        $promotion->setData('store_id', $storeId);

        return ['store_id'];
    }

    /**
     * @param Promotion $promotion
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyText(Promotion $promotion, array $arguments): array
    {
        if (!array_key_exists('title', $arguments)) {
            return [];
        }

        $promotion->setData('title', $this->nullableString($arguments, 'title'));

        return ['title'];
    }

    /**
     * @param Promotion $promotion
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyImage(Promotion $promotion, array $arguments): array
    {
        if (!array_key_exists('image', $arguments)) {
            return [];
        }

        $image = $this->nullableString($arguments, 'image');
        if ($image !== null) {
            $image = $this->mediaPath($image);
        }
        $promotion->setData('image', $image);

        return ['image'];
    }

    /**
     * @param Promotion $promotion
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyKeywords(Promotion $promotion, array $arguments): array
    {
        if (!array_key_exists('keywords', $arguments)) {
            return [];
        }

        $value = $arguments['keywords'];
        if ($value !== null && !is_array($value)) {
            throw new LocalizedException(__(
                'The "keywords" argument must be an array of strings, e.g. ["samsung", "galaxy"].'
            ));
        }

        $keywords = [];
        foreach ($value ?? [] as $keyword) {
            if (!is_string($keyword)) {
                throw new LocalizedException(__('Every keyword must be a string.'));
            }
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }
            if (str_contains($keyword, ',')) {
                throw new LocalizedException(__(
                    'The keyword "%1" contains a comma, which the module reads as a separator, so '
                    . 'it would become two keywords. Pass them as separate array items.',
                    $keyword
                ));
            }
            // Matching is case-insensitive, so "Samsung" and "samsung" are one.
            $keywords[mb_strtolower($keyword)] ??= $keyword;
        }

        $promotion->setData('keywords', $keywords === [] ? null : implode(', ', $keywords));

        return ['keywords'];
    }

    /**
     * @param Promotion $promotion
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyDates(Promotion $promotion, array $arguments): array
    {
        $changed = [];

        foreach (['active_from', 'active_to'] as $key) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $this->nullableString($arguments, $key);
            if ($value !== null) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    throw new LocalizedException(__(
                        'The "%1" argument must be a date as YYYY-MM-DD, not "%2".',
                        $key,
                        $value
                    ));
                }
            }
            $promotion->setData($key, $value);
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * @param Promotion $promotion
     * @param array<string, mixed> $arguments
     * @param bool $isCreate
     * @return string[]
     * @throws LocalizedException
     */
    private function applyNumbers(Promotion $promotion, array $arguments, bool $isCreate): array
    {
        $changed = [];

        if (array_key_exists('sort_order', $arguments)) {
            if (!is_int($arguments['sort_order'])) {
                throw new LocalizedException(__('The "sort_order" argument must be a whole number.'));
            }
            $promotion->setData('sort_order', $arguments['sort_order']);
            $changed[] = 'sort_order';
        } elseif ($isCreate) {
            $promotion->setData('sort_order', 0);
        }

        if (array_key_exists('is_active', $arguments)) {
            if (!is_bool($arguments['is_active'])) {
                throw new LocalizedException(
                    __('The "is_active" argument must be true or false, not a string or a number.')
                );
            }
            $promotion->setData('is_active', $arguments['is_active'] ? 1 : 0);
            $changed[] = 'is_active';
        } elseif ($isCreate) {
            $promotion->setData('is_active', 1);
        }

        return $changed;
    }

    /**
     * A string argument that null or "" clears.
     *
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return string|null
     * @throws LocalizedException
     */
    private function nullableString(array $arguments, string $key): ?string
    {
        $value = $arguments[$key];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new LocalizedException(__('The "%1" argument must be a string or null.', $key));
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $this->assertLength($key, $value);

        return $value;
    }

    /**
     * Normalise an image argument to a path under `pub/media` that exists.
     *
     * @param string $image
     * @return string
     * @throws LocalizedException
     */
    private function mediaPath(string $image): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $image)) {
            throw new LocalizedException(__(
                'Pass the image as a path under pub/media, such as "wysiwyg/promos/summer.jpg", not '
                . 'a URL. The storefront loads images only through its /media proxy, so a URL to '
                . 'another host never loads; upload the file with upload_media_gallery_asset and '
                . 'pass the path it returns.'
            ));
        }

        $path = ltrim(str_replace('\\', '/', $image), '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            throw new LocalizedException(__('The image path "%1" is not a path under pub/media.', $image));
        }

        if (!$this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->isFile($path)) {
            throw new LocalizedException(__(
                'There is no file at pub/media/%1. Upload it with upload_media_gallery_asset first, '
                . 'or check the path with search_media_gallery_assets.',
                $path
            ));
        }

        return $path;
    }

    /**
     * @param string $key
     * @param string $value
     * @return void
     * @throws LocalizedException
     */
    private function assertLength(string $key, string $value): void
    {
        $max = self::MAX_LENGTH[$key] ?? null;
        if ($max !== null && mb_strlen($value) > $max) {
            throw new LocalizedException(__(
                'The "%1" argument is %2 characters long; the column holds %3.',
                $key,
                mb_strlen($value),
                $max
            ));
        }
    }
}
