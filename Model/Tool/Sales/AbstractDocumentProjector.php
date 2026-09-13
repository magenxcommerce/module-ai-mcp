<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Sales\Api\Data\CommentInterface;

/**
 * Shared projection behaviour for the three documents an order produces.
 *
 * Each document has a summary — what a list of them is worth showing — and a
 * detail view behind its own get_* tool, the same split OrderProjector makes.
 * Only the two things every document shares live here: money, which Magento
 * hands back as a string, and comments, which all three keep in the same shape.
 */
abstract class AbstractDocumentProjector
{
    /**
     * The row a search result shows.
     *
     * @param object $document
     * @return array<string, mixed>
     */
    abstract public function toSummary(object $document): array;

    /**
     * The summary plus lines, totals and comments.
     *
     * @param object $document
     * @return array<string, mixed>
     */
    abstract public function toDetail(object $document): array;

    /**
     * Magento returns monetary columns as strings; a model reads a number more
     * reliably than "15.0000", and null must survive as null rather than 0.0.
     *
     * @param string|float|int|null $value
     * @return float|null
     */
    protected function money(string|float|int|null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * Invoice, shipment and credit memo comments are the same shape, and the
     * two flags are the whole point of reading them: one says the customer was
     * e-mailed, the other says the note is on the customer's own order view.
     *
     * @param array<int, CommentInterface>|null $comments
     * @return array<int, array<string, mixed>>
     */
    protected function comments(?array $comments): array
    {
        return array_map(
            static fn (CommentInterface $comment): array => [
                'comment' => $comment->getComment(),
                'customer_notified' => (bool) $comment->getIsCustomerNotified(),
                'visible_on_front' => (bool) $comment->getIsVisibleOnFront(),
                'created_at' => $comment->getCreatedAt(),
            ],
            array_values($comments ?? [])
        );
    }
}
