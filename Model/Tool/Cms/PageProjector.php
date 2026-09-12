<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magento\Cms\Api\Data\PageInterface;

/**
 * Shrinks a CMS page to something worth sending to a model.
 *
 * The page body is the reason this exists: a content page runs to tens of
 * kilobytes of HTML, so listings never carry it and the detail view only does
 * when asked. The design fields are grouped separately because they are gated
 * by their own ACL resource, and seeing them apart from the content makes the
 * split legible rather than arbitrary.
 */
class PageProjector
{
    /**
     * @param PageInterface $page
     * @return array<string, mixed>
     */
    public function toSummary(PageInterface $page): array
    {
        return [
            'page_id' => (int) $page->getId(),
            'identifier' => $page->getIdentifier(),
            'title' => $page->getTitle(),
            'is_active' => (bool) $page->isActive(),
            'page_layout' => $page->getPageLayout(),
            'sort_order' => $page->getSortOrder() === null ? null : (int) $page->getSortOrder(),
            'creation_time' => $page->getCreationTime(),
            'update_time' => $page->getUpdateTime(),
        ];
    }

    /**
     * @param PageInterface $page
     * @param bool $includeContent
     * @return array<string, mixed>
     */
    public function toDetail(PageInterface $page, bool $includeContent): array
    {
        $detail = $this->toSummary($page);
        $detail['content_heading'] = $page->getContentHeading();
        $detail['meta_title'] = $page->getMetaTitle();
        $detail['meta_keywords'] = $page->getMetaKeywords();
        $detail['meta_description'] = $page->getMetaDescription();

        // These are the fields Magento gates behind Magento_Cms::save_design,
        // and update_cms_page_design is the only tool that writes them.
        $detail['design'] = [
            'layout_update_xml' => $page->getLayoutUpdateXml(),
            'custom_theme' => $page->getCustomTheme(),
            'custom_root_template' => $page->getCustomRootTemplate(),
            'custom_layout_update_xml' => $page->getCustomLayoutUpdateXml(),
            'custom_theme_from' => $page->getCustomThemeFrom(),
            'custom_theme_to' => $page->getCustomThemeTo(),
        ];

        if ($includeContent) {
            $detail['content'] = $page->getContent();
        } else {
            $content = (string) $page->getContent();
            $detail['content_omitted'] = [
                'length' => strlen($content),
                'reason' => 'Pass include_content to read the page body.',
            ];
        }

        return $detail;
    }
}
