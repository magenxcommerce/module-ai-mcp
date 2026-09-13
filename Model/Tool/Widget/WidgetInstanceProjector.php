<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Widget;

use Magento\Widget\Model\Widget\Instance;

/**
 * Shrinks a widget instance to something worth sending to a model.
 */
class WidgetInstanceProjector
{
    /**
     * @param Instance $instance
     * @return array<string, mixed>
     */
    public function toSummary(Instance $instance): array
    {
        return [
            'instance_id' => (int) $instance->getId(),
            'title' => $instance->getTitle(),
            // The widget class, e.g. Magento\Cms\Block\Widget\Block.
            'instance_type' => $instance->getInstanceType(),
            'theme_id' => $instance->getThemeId() === null ? null : (int) $instance->getThemeId(),
            'store_ids' => $this->storeIds($instance),
            'sort_order' => (int) $instance->getSortOrder(),
        ];
    }

    /**
     * @param Instance $instance
     * @return array<string, mixed>
     */
    public function toDetail(Instance $instance): array
    {
        $detail = $this->toSummary($instance);
        // What the widget was configured with — for a CMS block widget, the
        // block id it renders; for a catalog widget, the conditions it matches.
        $detail['widget_parameters'] = $instance->getWidgetParameters();
        $detail['page_groups'] = $this->pageGroups($instance);

        return $detail;
    }

    /**
     * Magento stores the store ids as a comma-separated string, and 0 means
     * "all store views" rather than a store called zero.
     *
     * @param Instance $instance
     * @return array<int, int>
     */
    private function storeIds(Instance $instance): array
    {
        $stores = $instance->getStoreIds();
        if (is_array($stores)) {
            return array_map('intval', array_values($stores));
        }

        $stores = trim((string) $stores);
        if ($stores === '') {
            return [];
        }

        return array_map('intval', array_filter(array_map('trim', explode(',', $stores)), 'strlen'));
    }

    /**
     * Where the widget is placed: which pages, and in which layout container.
     *
     * @param Instance $instance
     * @return array<int, array<string, mixed>>
     */
    private function pageGroups(Instance $instance): array
    {
        $groups = $instance->getPageGroups();
        if (!is_array($groups)) {
            return [];
        }

        return array_map(
            static fn (array $group): array => [
                'page_group' => $group['page_group'] ?? null,
                'layout_handle' => $group['layout_handle'] ?? null,
                'block_reference' => $group['block_reference'] ?? ($group['block'] ?? null),
                'for' => $group['for'] ?? null,
                'template' => $group['template'] ?? null,
            ],
            array_values($groups)
        );
    }
}
