<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Email;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Email\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * Change the subject, body or styles of a custom e-mail template.
 *
 * Only templates that already exist as records: creating one is the admin's
 * "Load default template" flow, which picks the built-in template to start
 * from and is a decision about which mail is being replaced, not a field edit.
 */
class UpdateEmailTemplate extends AbstractTool
{
    /**
     * @param EmailTemplateLocator $locator
     * @param TemplateResource $templateResource
     * @param EmailTemplateProjector $projector
     */
    public function __construct(
        private readonly EmailTemplateLocator $locator,
        private readonly TemplateResource $templateResource,
        private readonly EmailTemplateProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_email_template';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change a custom e-mail template\'s subject, body or inline styles. This edits what '
            . 'customers receive, and it takes effect on the next mail the store sends — there is '
            . 'no draft. The body is Magento template markup: its {{...}} directives are executed '
            . 'at render time, so treat it as code and keep the directives the template already '
            . 'has unless you mean to change what the mail says. Only an existing custom template '
            . 'can be edited; create one from a built-in default in the admin first.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                [
                    'template_subject' => [
                        'type' => 'string',
                        'description' => 'Subject line. May contain template directives.',
                    ],
                    'template_text' => [
                        'type' => 'string',
                        'description' => 'The whole body, replacing what is there. Pass the '
                            . 'complete template, not a fragment.',
                    ],
                    'template_styles' => [
                        'type' => 'string',
                        'description' => 'Inline CSS for the template.',
                    ],
                ]
            ),
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
        $template = $this->locator->locate(
            $this->optionalInt($arguments, 'template_id'),
            $this->optionalString($arguments, 'template_code')
        );

        $changed = [];
        if (array_key_exists('template_subject', $arguments)) {
            $template->setTemplateSubject((string) $arguments['template_subject']);
            $changed[] = 'template_subject';
        }
        if (array_key_exists('template_text', $arguments)) {
            $template->setTemplateText((string) $arguments['template_text']);
            $changed[] = 'template_text';
        }
        if (array_key_exists('template_styles', $arguments)) {
            $template->setTemplateStyles((string) $arguments['template_styles']);
            $changed[] = 'template_styles';
        }

        if ($changed === []) {
            throw new LocalizedException(__(
                'Nothing to update: pass template_subject, template_text or template_styles.'
            ));
        }

        $this->templateResource->save($template);

        return [
            'updated' => true,
            'changed_fields' => $changed,
        ] + $this->projector->toSummary($template);
    }
}
