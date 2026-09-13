<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Email;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one custom e-mail template, body included.
 */
class GetEmailTemplate extends AbstractTool
{
    /**
     * @param EmailTemplateLocator $locator
     * @param EmailTemplateProjector $projector
     */
    public function __construct(
        private readonly EmailTemplateLocator $locator,
        private readonly EmailTemplateProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_email_template';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one custom e-mail template by template_id or template_code: its subject, body '
            . 'and inline styles. The body is Magento template markup, not plain HTML — the {{...}} '
            . 'directives in it are executed when the mail is rendered, so it is closer to code '
            . 'than to copy. Only customised templates can be read; the built-in defaults are '
            . 'files.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Email::template';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        return $this->projector->toDetail($this->locator->locate(
            $this->optionalInt($arguments, 'template_id'),
            $this->optionalString($arguments, 'template_code')
        ));
    }
}
