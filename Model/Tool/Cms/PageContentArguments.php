<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * The content fields shared by create_cms_page and update_cms_page.
 *
 * Deliberately excludes every design field. Magento gates those behind its own
 * ACL resource, Magento_Cms::save_design, and a tool carries exactly one
 * resource — so folding them in here would quietly let an integration granted
 * only "Save Page" rewrite a page's layout XML. update_cms_page_design is the
 * tool that writes them, and it declares that resource instead.
 */
class PageContentArguments
{
    /**
     * Apply the fields present in the arguments to a page.
     *
     * @param PageInterface $page
     * @param array<string, mixed> $arguments
     * @return string[] The fields that were set.
     * @throws LocalizedException
     */
    public function applyTo(PageInterface $page, array $arguments): array
    {
        $changed = [];

        foreach ($this->stringSetters() as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if ($value !== null && !is_string($value)) {
                throw new LocalizedException(__('The "%1" argument must be a string.', $key));
            }
            $page->{$setter}($value);
            $changed[] = $key;
        }

        if (array_key_exists('is_active', $arguments)) {
            if (!is_bool($arguments['is_active'])) {
                throw new LocalizedException(__(
                    'The "%1" argument must be true or false, not a string or a number.',
                    'is_active'
                ));
            }
            $page->setIsActive($arguments['is_active']);
            $changed[] = 'is_active';
        }

        if (array_key_exists('sort_order', $arguments)) {
            $sortOrder = $arguments['sort_order'];
            if (!is_int($sortOrder) && !(is_string($sortOrder) && ctype_digit($sortOrder))) {
                throw new LocalizedException(__('The "sort_order" argument must be a whole number.'));
            }
            $page->setSortOrder((string) (int) $sortOrder);
            $changed[] = 'sort_order';
        }

        return $changed;
    }

    /**
     * Schema fragment for the content fields.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'title' => ['type' => 'string', 'description' => 'The page title.'],
            'content' => [
                'type' => 'string',
                'description' => 'The page body HTML, replaced wholesale. Read the current body '
                    . 'with get_cms_page and include_content before rewriting it.',
            ],
            'content_heading' => ['type' => 'string', 'description' => 'Heading shown above the body.'],
            'page_layout' => [
                'type' => 'string',
                'description' => 'Layout handle, e.g. "1column", "2columns-left", "empty". This is '
                    . 'the one layout field that is part of saving a page rather than its design.',
            ],
            'meta_title' => ['type' => 'string'],
            'meta_keywords' => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
            'is_active' => ['type' => 'boolean', 'description' => 'Whether the page is published.'],
            'sort_order' => ['type' => 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function stringSetters(): array
    {
        return [
            'title' => 'setTitle',
            'content' => 'setContent',
            'content_heading' => 'setContentHeading',
            'page_layout' => 'setPageLayout',
            'meta_title' => 'setMetaTitle',
            'meta_keywords' => 'setMetaKeywords',
            'meta_description' => 'setMetaDescription',
        ];
    }
}
