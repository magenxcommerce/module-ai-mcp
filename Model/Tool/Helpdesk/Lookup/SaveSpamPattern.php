<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\SpamPatternFactory;
use Magenx\Helpdesk\Model\ResourceModel\SpamPattern as ResourceModel;
use Magento\Framework\Exception\LocalizedException;

/**
 * Create or edit a spam pattern.
 *
 * The one lookup here whose mistake is silent in the dangerous direction. A
 * pattern is a PCRE matched against inbound mail, and mail it matches never
 * becomes a ticket — so a rule that is broader than intended discards customer
 * enquiries with nothing to show for it. The module's own resource model
 * already refuses a pattern that will not compile; this tool adds the check it
 * cannot make, in `validateRow()`.
 */
class SaveSpamPattern extends AbstractHelpdeskSave
{
    /**
     * @param SpamPatternFactory $factory
     * @param ResourceModel $resource
     */
    public function __construct(
        private readonly SpamPatternFactory $factory,
        private readonly ResourceModel $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_helpdesk_spam_pattern';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::spam';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'spam patterns';
    }

    /**
     * @inheritDoc
     */
    protected function entityNameSingular(): string
    {
        return 'spam pattern';
    }

    /**
     * @inheritDoc
     */
    protected function idArgument(): string
    {
        return 'pattern_id';
    }

    /**
     * @inheritDoc
     */
    protected function fields(): array
    {
        return [
            'title' => [
                'type' => self::TYPE_STRING,
                'required' => true,
                'description' => 'What this rule is for, in words. It is the only thing that '
                    . 'explains the pattern to whoever reads it next.',
            ],
            'pattern' => [
                'type' => self::TYPE_STRING,
                'required' => true,
                'description' => 'A PCRE with delimiters, e.g. "/viagra/i" rather than "viagra". '
                    . 'Mail it matches is discarded, not queued.',
            ],
            'scope' => [
                'type' => self::TYPE_STRING,
                'enum' => ['subject', 'from', 'body'],
                'description' => 'Which part of the message the pattern is matched against. '
                    . 'Defaults to the subject.',
            ],
            'is_active' => [
                'type' => self::TYPE_BOOL,
                'description' => 'Whether the rule is applied to incoming mail.',
            ],
        ];
    }

    /**
     * Refuse a pattern that matches everything.
     *
     * The module's own resource model already refuses one that will not
     * compile, and says so well, so this does not repeat it. What it cannot
     * catch is a pattern that compiles and matches the empty string — an empty
     * body between the delimiters, a dot-star, a stray `?` on the only token.
     * That rule is not a broad filter, it is an off switch for the mailbox:
     * every inbound message matches, every one is discarded, and nothing
     * anywhere reports a ticket that was never created.
     *
     * @param object $row
     * @param array<string, mixed> $arguments
     * @return void
     * @throws LocalizedException
     */
    protected function validateRow(object $row, array $arguments): void
    {
        $pattern = (string) $row->getData('pattern');
        if ($pattern === '') {
            return;
        }

        // The warning an uncompilable pattern emits is swallowed the way the
        // module swallows it — with a scoped handler rather than `@`, which the
        // Magento standard rejects and which developer mode would promote to an
        // exception anyway. An uncompilable pattern returns false here, falls
        // through, and is refused by the resource model with its own message.
        set_error_handler(static fn (): bool => true);
        try {
            $matchesEverything = preg_match($pattern, '') === 1;
        } finally {
            restore_error_handler();
        }

        if ($matchesEverything) {
            throw new LocalizedException(__(
                'The pattern "%1" matches an empty message, so it would discard every incoming '
                . 'mail rather than filtering any. Narrow it, or set is_active false to turn the '
                . 'rule off deliberately.',
                $pattern
            ));
        }
    }

    /**
     * @inheritDoc
     */
    protected function newRow(): object
    {
        return $this->factory->create();
    }

    /**
     * @inheritDoc
     */
    protected function loadRow(int $rowId): ?object
    {
        $row = $this->factory->create();
        $this->resource->load($row, $rowId);

        return $row->getId() ? $row : null;
    }

    /**
     * @inheritDoc
     */
    protected function saveRow(object $row): void
    {
        $this->resource->save($row);
    }

    /**
     * @inheritDoc
     */
    protected function projectRow(object $row): array
    {
        return [
            'pattern_id' => (int) $row->getId(),
            'title' => $row->getData('title'),
            'scope' => $row->getData('scope'),
            'pattern' => $row->getData('pattern'),
            'is_active' => (bool) $row->getData('is_active'),
        ];
    }
}
