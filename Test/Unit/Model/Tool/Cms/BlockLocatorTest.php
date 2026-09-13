<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\Cms\BlockLocator;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Resolving the one CMS block a call means.
 *
 * Magento allows the same identifier on several blocks assigned to different
 * store views. Acting on an arbitrary one and reporting success by identifier
 * leaves the agent unable to tell what it touched — worst for delete, where
 * there is nothing to undo — so this behaviour is shared by the read, update
 * and delete tools alike and each says what it was about to do.
 *
 * @see BlockLocator
 */
class BlockLocatorTest extends TestCase
{
    private BlockRepositoryInterface&MockObject $blockRepository;
    private BlockLocator $locator;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->blockRepository = $this->createMock(BlockRepositoryInterface::class);
        $this->locator = new BlockLocator(
            $this->blockRepository,
            $this->createMock(SearchCriteriaBuilder::class)
        );
    }

    /**
     * @return void
     */
    public function testTheOnlyMatchIsReturned(): void
    {
        $block = $this->block(3);
        $this->repositoryReturns([$block]);

        $this->assertSame($block, $this->locator->locate('footer-links', null, 'read'));
    }

    /**
     * @return void
     */
    public function testAnUnknownIdentifierIsNamed(): void
    {
        $this->repositoryReturns([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No CMS block exists with identifier "footer-links".');
        $this->locator->locate('footer-links', null, 'read');
    }

    /**
     * @return void
     */
    public function testAnUnknownIdentifierWithABlockIdSaysBoth(): void
    {
        $this->repositoryReturns([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'No CMS block exists with identifier "footer-links" and block_id 9.'
        );
        $this->locator->locate('footer-links', 9, 'change');
    }

    /**
     * @return void
     */
    public function testAnAmbiguousIdentifierNamesTheCandidatesAndTheVerb(): void
    {
        $this->repositoryReturns([$this->block(3), $this->block(7)]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The identifier "footer-links" matches 2 CMS blocks (block_id 3, 7). '
            . 'Pass block_id to say which one to delete.'
        );
        $this->locator->locate('footer-links', null, 'delete');
    }

    /**
     * @return void
     */
    public function testTheSchemaDescribesWhatTheToolWillDo(): void
    {
        $properties = $this->locator->schemaProperties('change');

        $this->assertSame(['identifier', 'block_id'], array_keys($properties));
        $this->assertSame('Identifier of the block to change.', $properties['identifier']['description']);
    }

    /**
     * @param array<int, BlockInterface> $blocks
     * @return void
     */
    private function repositoryReturns(array $blocks): void
    {
        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn($blocks);
        $this->blockRepository->method('getList')->willReturn($results);
    }

    /**
     * @param int $blockId
     * @return BlockInterface&MockObject
     */
    private function block(int $blockId): BlockInterface&MockObject
    {
        $block = $this->createMock(BlockInterface::class);
        $block->method('getId')->willReturn($blockId);

        return $block;
    }
}
