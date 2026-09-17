<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Api\Data\CommentInterfaceFactory;
use Magenx\Rma\Api\RmaCommentManagementInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Add a comment to a return.
 *
 * `visible_to_customer` has no default on purpose. The same table holds staff
 * notes and replies to the customer, and the only thing separating them is that
 * flag — so defaulting it either way would silently turn an internal note into
 * something the customer reads, or bury a reply meant for them. The caller has
 * to say which it is.
 */
class AddRmaComment extends AbstractTool
{
    /**
     * @param RmaLocator $locator
     * @param RmaCommentManagementInterface $commentManagement
     * @param CommentInterfaceFactory $commentFactory
     */
    public function __construct(
        private readonly RmaLocator $locator,
        private readonly RmaCommentManagementInterface $commentManagement,
        private readonly CommentInterfaceFactory $commentFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'add_rma_comment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a comment to a return. visible_to_customer is required and has no default: '
            . 'true writes a reply the customer can read, false writes a staff-only note, and the '
            . 'two are stored the same way apart from that flag. The comment is recorded as '
            . 'coming from staff. Adding one does not email the customer on its own — only '
            . 'update_rma changing the status does that.';
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
                    'comment' => ['type' => 'string', 'description' => 'The comment text.'],
                    'visible_to_customer' => [
                        'type' => 'boolean',
                        'description' => 'True for a reply the customer can read, false for a '
                            . 'staff-only note. Required — there is no safe default.',
                    ],
                    'author_name' => [
                        'type' => 'string',
                        'description' => 'Name to record against the comment. Defaults to "Admin".',
                    ],
                ]
            ),
            'required' => ['comment', 'visible_to_customer'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_manage';
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
    protected function isDestructive(): bool
    {
        // Appends a comment; nothing already on the return changes.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $rma = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'rma_id')
        );

        $text = $this->requireString($arguments, 'comment');

        $visible = $this->optionalBool($arguments, 'visible_to_customer');
        if ($visible === null) {
            throw new LocalizedException(__(
                'The "visible_to_customer" argument is required: true writes a reply the customer '
                . 'can read, false writes a staff-only note.'
            ));
        }

        $comment = $this->commentFactory->create();
        $comment->setComment($text);
        $comment->setIsVisibleToCustomer($visible);
        $comment->setAuthorType(CommentInterface::AUTHOR_TYPE_ADMIN);
        $comment->setAuthorName((string) $this->optionalString($arguments, 'author_name', 'Admin'));

        $saved = $this->commentManagement->save((int) $rma->getEntityId(), $comment);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'rma_id' => $rma->getEntityId(),
            'increment_id' => $rma->getIncrementId(),
            'comment_id' => $saved->getEntityId(),
            'is_visible_to_customer' => $saved->getIsVisibleToCustomer(),
            'author_type' => $saved->getAuthorType(),
        ];
    }
}
