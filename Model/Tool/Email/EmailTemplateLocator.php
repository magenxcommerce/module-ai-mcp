<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Email;

use Magento\Email\Model\Template;
use Magento\Email\Model\TemplateFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Loads a customised e-mail template by id or code.
 *
 * Only templates that exist in the database can be reached. Magento's defaults
 * live in module files, and loading one of those codes here returns an empty
 * model rather than failing — which a tool must not mistake for a template with
 * no content.
 */
class EmailTemplateLocator
{
    /**
     * @param TemplateFactory $templateFactory
     */
    public function __construct(
        private readonly TemplateFactory $templateFactory
    ) {
    }

    /**
     * @param int|null $templateId
     * @param string|null $templateCode
     * @return Template
     * @throws LocalizedException
     */
    public function locate(?int $templateId, ?string $templateCode): Template
    {
        $template = $this->templateFactory->create();

        if ($templateId !== null) {
            $template->load($templateId);
            if (!$template->getId()) {
                throw new LocalizedException(
                    __('No custom e-mail template exists with template_id %1.', $templateId)
                );
            }

            return $template;
        }

        if ($templateCode === null) {
            throw new LocalizedException(__('Pass template_id or template_code.'));
        }

        $template->load($templateCode, 'template_code');
        if (!$template->getId()) {
            throw new LocalizedException(__(
                'No custom e-mail template exists with template_code "%1". Magento\'s built-in '
                . 'templates ship as files and have no record here until someone creates an '
                . 'override from one in the admin; list_email_templates shows what this store has.',
                $templateCode
            ));
        }

        return $template;
    }

    /**
     * Schema fragment for the arguments that name a template.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'template_id' => [
                'type' => 'integer',
                'description' => 'Numeric id, as list_email_templates reports it. Either this or '
                    . 'template_code is required.',
            ],
            'template_code' => [
                'type' => 'string',
                'description' => 'The template\'s own name, as shown in the admin grid.',
            ],
        ];
    }
}
