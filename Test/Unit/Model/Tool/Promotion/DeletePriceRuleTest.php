<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\Promotion\CartPriceRuleProjector;
use Magenx\AiMcp\Model\Tool\Promotion\CatalogPriceRuleProjector;
use Magenx\AiMcp\Model\Tool\Promotion\DeleteCartPriceRule;
use Magenx\AiMcp\Model\Tool\Promotion\DeleteCatalogPriceRule;
use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Api\Data\RuleInterface as CatalogRuleInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\Data\RuleInterface as CartRuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Deleting price rules.
 *
 * Both tools read the rule before removing it, and the ordering is the thing
 * worth pinning: the confirm preview an agent sees would otherwise be a bare
 * `rule_id`, and after the delete there is nothing left to describe. Reading
 * first is what lets the result name the campaign that just went.
 *
 * @see DeleteCartPriceRule
 * @see DeleteCatalogPriceRule
 */
class DeletePriceRuleTest extends TestCase
{
    private RuleRepositoryInterface&MockObject $ruleRepository;
    private CatalogRuleRepositoryInterface&MockObject $catalogRuleRepository;
    private DeleteCartPriceRule $cartTool;
    private DeleteCatalogPriceRule $catalogTool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->ruleRepository = $this->createMock(RuleRepositoryInterface::class);
        $this->catalogRuleRepository = $this->createMock(CatalogRuleRepositoryInterface::class);

        $cartProjector = $this->createMock(CartPriceRuleProjector::class);
        $cartProjector->method('toSummary')->willReturn(['name' => 'Autumn sale']);
        $catalogProjector = $this->createMock(CatalogPriceRuleProjector::class);
        $catalogProjector->method('toArray')->willReturn(['name' => 'Clearance']);

        $this->cartTool = new DeleteCartPriceRule($this->ruleRepository, $cartProjector);
        $this->catalogTool = new DeleteCatalogPriceRule($this->catalogRuleRepository, $catalogProjector);
    }

    /**
     * @return void
     */
    public function testBothAreWritesBehindTheirOwnResources(): void
    {
        $this->assertTrue($this->cartTool->isWrite());
        $this->assertSame('Magento_SalesRule::quote', $this->cartTool->getAclResource());

        $this->assertTrue($this->catalogTool->isWrite());
        $this->assertSame('Magento_CatalogRule::promo_catalog', $this->catalogTool->getAclResource());
    }

    /**
     * Neither overrides the default, which is what a delete should advertise.
     *
     * @return void
     */
    public function testBothAdvertiseThemselvesAsDestructive(): void
    {
        $this->assertTrue($this->cartTool->getAnnotations()['destructiveHint']);
        $this->assertTrue($this->catalogTool->getAnnotations()['destructiveHint']);
    }

    /**
     * @return void
     */
    public function testTheCartRuleIsReadBeforeItIsDeleted(): void
    {
        $order = [];
        $this->ruleRepository->method('getById')->willReturnCallback(
            function () use (&$order): CartRuleInterface {
                $order[] = 'read';

                return $this->createMock(CartRuleInterface::class);
            }
        );
        $this->ruleRepository->method('deleteById')->willReturnCallback(static function () use (&$order): bool {
            $order[] = 'delete';

            return true;
        });

        $result = $this->cartTool->execute(['rule_id' => 4]);

        $this->assertSame(['read', 'delete'], $order);
        $this->assertTrue($result['deleted']);
        $this->assertSame('Autumn sale', $result['name']);
        $this->assertStringContainsString('coupon codes', $result['note']);
    }

    /**
     * A catalog rule's prices survive the rule until the indexer runs, so a
     * caller who reads "deleted" as "prices are back to normal" is wrong.
     *
     * @return void
     */
    public function testDeletingACatalogRuleFlagsTheReindex(): void
    {
        $this->catalogRuleRepository->method('get')->willReturn($this->createMock(CatalogRuleInterface::class));

        $result = $this->catalogTool->execute(['rule_id' => 12]);

        $this->assertTrue($result['deleted']);
        $this->assertTrue($result['reindex_required']);
        $this->assertSame('Clearance', $result['name']);
    }

    /**
     * @return void
     */
    public function testAnUnknownCartRuleIsRefusedByIdAndNothingIsDeleted(): void
    {
        $this->ruleRepository->method('getById')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $this->ruleRepository->expects($this->never())->method('deleteById');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No cart price rule exists with rule_id 99');

        $this->cartTool->execute(['rule_id' => 99]);
    }

    /**
     * @return void
     */
    public function testAnUnknownCatalogRuleIsRefusedByIdAndNothingIsDeleted(): void
    {
        $this->catalogRuleRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $this->catalogRuleRepository->expects($this->never())->method('deleteById');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No catalog price rule exists with rule_id 99');

        $this->catalogTool->execute(['rule_id' => 99]);
    }
}
