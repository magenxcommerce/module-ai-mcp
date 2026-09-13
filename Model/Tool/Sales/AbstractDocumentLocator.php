<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Turns the document argument the get_* sales tools accept into a loaded document.
 *
 * This is OrderLocator's reasoning applied to the three documents an order
 * produces: an agent holds the increment id it read off a search result or a
 * customer e-mail, while every repository takes the numeric entity id. An
 * increment id is unique per store rather than per installation, so two store
 * views sharing a prefix can both produce "000000123" — acting on an arbitrary
 * one of them and reporting success by increment id would leave the agent
 * unable to tell which document it read.
 */
abstract class AbstractDocumentLocator
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * Load by entity id, letting NoSuchEntityException through.
     *
     * @param int $entityId
     * @return object
     * @throws NoSuchEntityException
     */
    abstract protected function fetchById(int $entityId): object;

    /**
     * Ask this document's repository for matches.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     */
    abstract protected function searchDocuments(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * What this document is called in an error an agent has to act on, e.g.
     * "invoice" or "credit memo".
     *
     * @return string
     */
    abstract protected function documentLabel(): string;

    /**
     * Load the document named by an increment id or an entity id.
     *
     * @param string|null $incrementId
     * @param int|null $entityId
     * @return object
     * @throws LocalizedException
     */
    public function locate(?string $incrementId, ?int $entityId): object
    {
        if ($entityId !== null) {
            try {
                return $this->fetchById($entityId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(
                    __('No %1 exists with entity_id %2.', $this->documentLabel(), $entityId)
                );
            }
        }

        if ($incrementId === null) {
            throw new LocalizedException(
                __('Pass entity_id, or increment_id — the number on the %1 itself.', $this->documentLabel())
            );
        }

        $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId);
        $matches = array_values(
            $this->searchDocuments($this->searchCriteriaBuilder->create())->getItems()
        );

        if ($matches === []) {
            throw new LocalizedException(
                __('No %1 exists with increment_id "%2".', $this->documentLabel(), $incrementId)
            );
        }

        if (count($matches) > 1) {
            throw new LocalizedException(__(
                'The increment_id "%1" matches %2 %3 records (entity_id %4). Pass entity_id to say which one.',
                $incrementId,
                count($matches),
                $this->documentLabel(),
                implode(', ', array_map(static fn (object $d): string => (string) $d->getEntityId(), $matches))
            ));
        }

        return $matches[0];
    }

    /**
     * Schema fragment for the two arguments that name a document.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'entity_id' => [
                'type' => 'integer',
                'description' => sprintf(
                    'Numeric %s id, as search_%ss reports it. Either this or increment_id is required.',
                    $this->documentLabel(),
                    str_replace(' ', '_', $this->documentLabel())
                ),
            ],
            'increment_id' => [
                'type' => 'string',
                'description' => sprintf(
                    'The %s\'s own increment id, which is not the order\'s. Use entity_id instead '
                        . 'when an increment id is shared across store views.',
                    $this->documentLabel()
                ),
            ],
        ];
    }
}
