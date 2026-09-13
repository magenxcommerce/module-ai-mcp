<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Email;

use Magento\Email\Model\Template;

/**
 * Shrinks an e-mail template to something worth sending to a model.
 */
class EmailTemplateProjector
{
    /**
     * @param Template $template
     * @return array<string, mixed>
     */
    public function toSummary(Template $template): array
    {
        return [
            'template_id' => (int) $template->getId(),
            'template_code' => $template->getTemplateCode(),
            'template_subject' => $template->getTemplateSubject(),
            // Which built-in template this was created from, e.g.
            // "sales_email_order_template".
            'orig_template_code' => $template->getOrigTemplateCode(),
            'is_legacy' => (bool) $template->isLegacy(),
            'added_at' => $template->getAddedAt(),
            'modified_at' => $template->getModifiedAt(),
        ];
    }

    /**
     * @param Template $template
     * @return array<string, mixed>
     */
    public function toDetail(Template $template): array
    {
        $detail = $this->toSummary($template);
        $detail['template_text'] = $template->getTemplateText();
        $detail['template_styles'] = $template->getTemplateStyles();

        return $detail;
    }
}
