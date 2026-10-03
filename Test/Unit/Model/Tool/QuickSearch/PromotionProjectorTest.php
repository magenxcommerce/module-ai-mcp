<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\QuickSearch;

use Magenx\AiMcp\Model\Tool\QuickSearch\PromotionProjector;
use Magenx\AiMcp\Model\Tool\QuickSearch\StorefrontVisibility;
use Magenx\AiMcp\Model\Tool\QuickSearch\TargetValidator;
use Magenx\QuickSearchGraphQl\Model\Promotion;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The summary of a promotion, and the two fields in it that are derived.
 */
class PromotionProjectorTest extends TestCase
{
    private PromotionProjector $projector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('scopeDate')->willReturn(new \DateTime('2026-10-03'));

        $this->projector = new PromotionProjector(
            $timezone,
            $this->createMock(TargetValidator::class),
            $this->createMock(StorefrontVisibility::class)
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function windows(): array
    {
        return [
            'no dates' => [[], PromotionProjector::SCHEDULE_LIVE],
            'starts tomorrow' => [['active_from' => '2026-10-04'], PromotionProjector::SCHEDULE_SCHEDULED],
            'starts today' => [['active_from' => '2026-10-03'], PromotionProjector::SCHEDULE_LIVE],
            // active_to is inclusive, as the storefront reads it.
            'ends today' => [['active_to' => '2026-10-03'], PromotionProjector::SCHEDULE_LIVE],
            'ended yesterday' => [['active_to' => '2026-10-02'], PromotionProjector::SCHEDULE_EXPIRED],
            'disabled' => [['is_active' => 0], PromotionProjector::SCHEDULE_DISABLED],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param string $expected
     * @return void
     */
    #[DataProvider('windows')]
    public function testTheScheduleIsJudgedAgainstTheStoresToday(array $data, string $expected): void
    {
        $this->assertSame($expected, $this->projector->toSummary($this->promotion($data))['schedule']);
    }

    /**
     * A numeric keyword must come back as a string, not as an array key PHP
     * has turned into an integer.
     *
     * @return void
     */
    public function testKeywordsAreReadAsTheStorefrontReadsThem(): void
    {
        $summary = $this->projector->toSummary($this->promotion(['keywords' => 'Samsung, galaxy,,SAMSUNG, 2026']));

        $this->assertSame(['samsung', 'galaxy', '2026'], $summary['keywords']);
        $this->assertFalse($summary['general']);
    }

    /**
     * @return void
     */
    public function testAPromotionWithoutKeywordsIsSaidToBeGeneral(): void
    {
        $summary = $this->projector->toSummary($this->promotion(['keywords' => null]));

        $this->assertSame([], $summary['keywords']);
        $this->assertTrue($summary['general']);
    }

    /**
     * @param array<string, mixed> $data
     * @return Promotion
     */
    private function promotion(array $data): Promotion
    {
        $promotion = new Promotion();
        foreach ($data + ['promotion_id' => 7, 'type' => 'product', 'target' => 'MB01', 'is_active' => 1] as $k => $v) {
            $promotion->setData($k, $v);
        }

        return $promotion;
    }
}
