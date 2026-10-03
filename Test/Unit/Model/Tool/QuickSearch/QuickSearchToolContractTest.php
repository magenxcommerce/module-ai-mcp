<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\QuickSearch;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\QuickSearch\CreateQuickSearchPromotion;
use Magenx\AiMcp\Model\Tool\QuickSearch\DeleteQuickSearchPromotion;
use Magenx\AiMcp\Model\Tool\QuickSearch\GetQuickSearchPromotion;
use Magenx\AiMcp\Model\Tool\QuickSearch\PromotionArguments;
use Magenx\AiMcp\Model\Tool\QuickSearch\PromotionLocator;
use Magenx\AiMcp\Model\Tool\QuickSearch\PromotionProjector;
use Magenx\AiMcp\Model\Tool\QuickSearch\UpdateQuickSearchPromotion;
use Magenx\QuickSearchGraphQl\Model\Promotion;
use Magenx\QuickSearchGraphQl\Model\PromotionFactory;
use Magenx\QuickSearchGraphQl\Model\PromotionRepository;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

/**
 * The surface the quick search promotion tools advertise, and the few things
 * each one does besides hand its arguments on.
 */
class QuickSearchToolContractTest extends TestCase
{
    /**
     * The module declares one resource for the grid and every action on it,
     * so the tools sit behind that one rather than inventing finer ones no
     * role would hold.
     *
     * @return void
     */
    public function testEveryToolSitsBehindThePromotionResourceWithAClosedSchema(): void
    {
        foreach ($this->tools() as $tool) {
            $this->assertSame('Magenx_QuickSearchGraphQl::promotion', $tool->getAclResource(), $tool->getName());
            $this->assertFalse($tool->getInputSchema()['additionalProperties'], $tool->getName());
        }
    }

    /**
     * @return void
     */
    public function testTheWritesAndTheirHints(): void
    {
        [$get, $create, $update, $delete] = $this->tools();

        $this->assertFalse($get->isWrite());
        $this->assertSame(['type', 'target'], $create->getInputSchema()['required']);
        $this->assertFalse($create->getAnnotations()['destructiveHint']);
        $this->assertTrue($update->getAnnotations()['idempotentHint']);
        $this->assertTrue($delete->getAnnotations()['destructiveHint']);
    }

    /**
     * @return void
     */
    public function testAnUnknownPromotionPointsAtTheSearchTool(): void
    {
        $repository = $this->createMock(PromotionRepository::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Use search_quick_search_promotions');

        (new PromotionLocator($repository))->locate(99);
    }

    /**
     * @return void
     */
    public function testAnUpdateThatChangesNothingIsRefusedAndNotSaved(): void
    {
        $locator = $this->createMock(PromotionLocator::class);
        $locator->method('locate')->willReturn($this->promotion());
        $arguments = $this->createMock(PromotionArguments::class);
        $arguments->method('applyTo')->willReturn([]);
        $repository = $this->createMock(PromotionRepository::class);
        $repository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Nothing to update');

        (new UpdateQuickSearchPromotion(
            $locator,
            $repository,
            $arguments,
            $this->createMock(PromotionProjector::class)
        ))->execute(['promotion_id' => 7]);
    }

    /**
     * The only undo a delete has is the row it returns.
     *
     * @return void
     */
    public function testDeleteReturnsTheRowItRemoved(): void
    {
        $promotion = $this->promotion();
        $locator = $this->createMock(PromotionLocator::class);
        $locator->method('locate')->willReturn($promotion);
        $repository = $this->createMock(PromotionRepository::class);
        $repository->expects($this->once())->method('delete')->with($promotion);
        $projector = $this->createMock(PromotionProjector::class);
        $projector->method('toSummary')->willReturn(['promotion_id' => 7, 'target' => 'MB01']);

        $result = (new DeleteQuickSearchPromotion($locator, $repository, $projector))
            ->execute(['promotion_id' => 7]);

        $this->assertTrue($result['deleted']);
        $this->assertSame(['promotion_id' => 7, 'target' => 'MB01'], $result['promotion']);
    }

    /**
     * @return AbstractTool[]
     */
    private function tools(): array
    {
        $locator = $this->createMock(PromotionLocator::class);
        $locator->method('schemaProperties')->willReturn(['promotion_id' => ['type' => 'integer']]);
        $arguments = $this->createMock(PromotionArguments::class);
        $arguments->method('schemaProperties')->willReturn([]);
        $repository = $this->createMock(PromotionRepository::class);
        $projector = $this->createMock(PromotionProjector::class);

        return [
            new GetQuickSearchPromotion($locator, $projector),
            new CreateQuickSearchPromotion(
                $this->createMock(PromotionFactory::class),
                $repository,
                $arguments,
                $projector
            ),
            new UpdateQuickSearchPromotion($locator, $repository, $arguments, $projector),
            new DeleteQuickSearchPromotion($locator, $repository, $projector),
        ];
    }

    /**
     * @return Promotion
     */
    private function promotion(): Promotion
    {
        return (new Promotion())->setData('promotion_id', 7);
    }
}
