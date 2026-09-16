<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\Promotion\ApplyCatalogPriceRules;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Asking for catalog rule prices to be rebuilt.
 *
 * The tool exists to be honest about something a caller will otherwise get
 * wrong: nothing is re-priced during this call. It marks the indexer invalid
 * and cron does the work, exactly as the admin's "Apply Rules" button does,
 * because re-applying rules across a real catalogue outlasts an HTTP request.
 *
 * So the tests are about the two things that make that honest — the word
 * "scheduled" rather than "applied", and a note saying when prices actually
 * move — plus the one failure mode with a wrong-looking success: an indexer
 * that is not registered at all.
 *
 * @see ApplyCatalogPriceRules::execute
 */
class ApplyCatalogPriceRulesTest extends TestCase
{
    private IndexerRegistry&MockObject $indexerRegistry;
    private ApplyCatalogPriceRules $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->tool = new ApplyCatalogPriceRules($this->indexerRegistry);
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheCatalogRuleResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_CatalogRule::promo_catalog', $this->tool->getAclResource());
    }

    /**
     * It schedules a rebuild of derived data: nothing is removed, and an
     * indexer already invalid stays invalid.
     *
     * @return void
     */
    public function testItAdvertisesItselfAsIdempotentAndNotDestructive(): void
    {
        $annotations = $this->tool->getAnnotations();

        $this->assertFalse($annotations['destructiveHint']);
        $this->assertTrue($annotations['idempotentHint']);
    }

    /**
     * @return void
     */
    public function testItTakesNoArguments(): void
    {
        $schema = $this->tool->getInputSchema();

        $this->assertSame([], $schema['properties']);
        $this->assertFalse($schema['additionalProperties']);
    }

    /**
     * @return void
     */
    public function testItInvalidatesTheCatalogRuleIndexerAndSaysSo(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->once())->method('invalidate');
        $this->indexerRegistry->expects($this->once())
            ->method('get')
            ->with('catalogrule_rule')
            ->willReturn($indexer);

        $result = $this->tool->execute([]);

        // "scheduled", not "applied" — the difference is the whole point.
        $this->assertTrue($result['scheduled']);
        $this->assertSame('catalogrule_rule', $result['indexer']);
        $this->assertStringContainsString('cron', $result['note']);
    }

    /**
     * An unregistered indexer would otherwise surface as a framework exception
     * with no hint that the cause is a disabled module.
     *
     * @return void
     */
    public function testAnUnregisteredIndexerIsReportedAsSuch(): void
    {
        $this->indexerRegistry->method('get')->willThrowException(new \InvalidArgumentException('nope'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Magento_CatalogRule is enabled');

        $this->tool->execute([]);
    }
}
