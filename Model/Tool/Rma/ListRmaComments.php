<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Api\RmaCommentManagementInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * Read a return's correspondence.
 */
class ListRmaComments extends AbstractTool
{
    /**
     * @param RmaLocator $locator
     * @param RmaCommentManagementInterface $commentManagement
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     */
    public function __construct(
        private readonly RmaLocator $locator,
        private readonly RmaCommentManagementInterface $commentManagement,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_rma_comments';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the comments on one return, newest first. Each says whether it came from the '
            . 'customer or from staff, and whether the customer can see it — an internal note and '
            . 'a reply to the customer both live here, told apart only by is_visible_to_customer.';
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
                $this->pagingSchema()
            ),
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
    public function execute(array $arguments): array
    {
        $rma = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'rma_id')
        );

        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->setField('created_at')->setDirection('DESC')->create()
        );

        $result = $this->commentManagement->getList(
            (int) $rma->getEntityId(),
            $this->searchCriteriaBuilder->create()
        );

        return [
            'rma_id' => $rma->getEntityId(),
            'increment_id' => $rma->getIncrementId(),
            'total_count' => $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                static fn (CommentInterface $comment): array => [
                    'comment_id' => $comment->getEntityId(),
                    'author_type' => $comment->getAuthorType(),
                    'author_name' => $comment->getAuthorName(),
                    'comment' => $comment->getComment(),
                    'is_visible_to_customer' => $comment->getIsVisibleToCustomer(),
                    'created_at' => $comment->getCreatedAt(),
                ],
                array_values($result->getItems())
            ),
        ];
    }
}
