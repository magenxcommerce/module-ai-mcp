<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\Sales\AbstractDocumentLocator;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

/**
 * Resolving an invoice, shipment or credit memo from what an agent holds.
 *
 * An increment id is unique per store rather than per installation, so the one
 * case that must never quietly succeed is an ambiguous one: acting on an
 * arbitrary match and reporting it back by increment id leaves the agent unable
 * to tell which document it read.
 *
 * @see AbstractDocumentLocator
 */
class DocumentLocatorTest extends TestCase
{
    /**
     * @return void
     */
    public function testEntityIdWins(): void
    {
        $document = $this->document(7);
        $locator = $this->locator(['12' => $document], []);

        $this->assertSame($document, $locator->locate('000000123', 12));
    }

    /**
     * @return void
     */
    public function testMissingEntityIdIsNamed(): void
    {
        $locator = $this->locator([], []);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No invoice exists with entity_id 99.');
        $locator->locate(null, 99);
    }

    /**
     * @return void
     */
    public function testIncrementIdResolvesToTheOnlyMatch(): void
    {
        $document = $this->document(7);
        $locator = $this->locator([], [$document]);

        $this->assertSame($document, $locator->locate('000000123', null));
    }

    /**
     * @return void
     */
    public function testUnknownIncrementIdIsNamed(): void
    {
        $locator = $this->locator([], []);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No invoice exists with increment_id "000000123".');
        $locator->locate('000000123', null);
    }

    /**
     * The whole reason this class exists: two store views can both produce
     * "000000123", and the error has to name the ids that would let the agent
     * choose rather than picking one.
     *
     * @return void
     */
    public function testAmbiguousIncrementIdNamesTheCandidates(): void
    {
        $locator = $this->locator([], [$this->document(7), $this->document(9)]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The increment_id "000000123" matches 2 invoice records (entity_id 7, 9). '
            . 'Pass entity_id to say which one.'
        );
        $locator->locate('000000123', null);
    }

    /**
     * @return void
     */
    public function testNeitherArgumentIsRefused(): void
    {
        $locator = $this->locator([], []);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pass entity_id, or increment_id — the number on the invoice itself.');
        $locator->locate(null, null);
    }

    /**
     * @return void
     */
    public function testSchemaNamesBothArguments(): void
    {
        $properties = $this->locator([], [])->schemaProperties();

        $this->assertSame(['entity_id', 'increment_id'], array_keys($properties));
        $this->assertStringContainsString('search_invoices', $properties['entity_id']['description']);
    }

    /**
     * A locator over an in-memory set of documents.
     *
     * @param array<string, object> $byId
     * @param array<int, object> $byIncrementId
     * @return AbstractDocumentLocator
     */
    private function locator(array $byId, array $byIncrementId): AbstractDocumentLocator
    {
        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn($byIncrementId);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('create')
            ->willReturn($this->createMock(SearchCriteriaInterface::class));

        return new class ($searchCriteriaBuilder, $byId, $results) extends AbstractDocumentLocator {
            /**
             * @param SearchCriteriaBuilder $searchCriteriaBuilder
             * @param array<string, object> $byId
             * @param SearchResultsInterface $results
             */
            public function __construct(
                SearchCriteriaBuilder $searchCriteriaBuilder,
                private readonly array $byId,
                private readonly SearchResultsInterface $results
            ) {
                parent::__construct($searchCriteriaBuilder);
            }

            /**
             * @inheritDoc
             */
            protected function fetchById(int $entityId): object
            {
                return $this->byId[(string) $entityId]
                    ?? throw new NoSuchEntityException(__('No such entity.'));
            }

            /**
             * @inheritDoc
             */
            protected function searchDocuments(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
            {
                return $this->results;
            }

            /**
             * @inheritDoc
             */
            protected function documentLabel(): string
            {
                return 'invoice';
            }
        };
    }

    /**
     * @param int $entityId
     * @return object
     */
    private function document(int $entityId): object
    {
        return new class ($entityId) {
            /**
             * @param int $entityId
             */
            public function __construct(private readonly int $entityId)
            {
            }

            /**
             * @return int
             */
            public function getEntityId(): int
            {
                return $this->entityId;
            }
        };
    }
}
