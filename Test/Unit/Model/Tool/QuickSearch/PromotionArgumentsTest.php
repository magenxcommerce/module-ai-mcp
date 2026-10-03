<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\QuickSearch;

use Magenx\AiMcp\Model\Tool\QuickSearch\PromotionArguments;
use Magenx\AiMcp\Model\Tool\QuickSearch\TargetValidator;
use Magenx\QuickSearchGraphQl\Model\Promotion;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Applying the writable fields of a quick search promotion.
 *
 * The quick search module validates in its admin controller, not its model, so
 * everything a tool must not save is decided here. Most of what is refused
 * would otherwise save cleanly and then vanish from the storefront without a
 * word, which is what these tests are organised around.
 *
 * @see PromotionArguments::applyTo
 */
class PromotionArgumentsTest extends TestCase
{
    private TargetValidator&MockObject $targetValidator;
    private StoreManagerInterface&MockObject $storeManager;
    private ReadInterface&MockObject $media;
    private PromotionArguments $arguments;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->targetValidator = $this->createMock(TargetValidator::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->media = $this->createMock(ReadInterface::class);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($this->media);

        $this->arguments = new PromotionArguments($this->targetValidator, $this->storeManager, $filesystem);
    }

    /**
     * @return void
     */
    public function testCreateRequiresATypeAndATarget(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"target" argument is required');

        $this->arguments->applyTo(new Promotion(), ['type' => 'product'], true);
    }

    /**
     * The admin form's defaults: every store view, enabled, first in line.
     *
     * @return void
     */
    public function testCreateFillsTheDefaultsTheAdminFormWould(): void
    {
        $promotion = new Promotion();

        $changed = $this->arguments->applyTo($promotion, ['type' => 'product', 'target' => 'MB01'], true);

        $this->assertSame(['type', 'target'], $changed);
        $this->assertSame(0, $promotion->getData('store_id'));
        $this->assertSame(1, $promotion->getData('is_active'));
        $this->assertSame(0, $promotion->getData('sort_order'));
    }

    /**
     * @return void
     */
    public function testAnUnknownTypeIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be one of');

        $this->arguments->applyTo(new Promotion(), ['type' => 'PRODUCT', 'target' => 'MB01'], true);
    }

    /**
     * @return void
     */
    public function testACategoryTargetMustBeNumeric(): void
    {
        $this->targetValidator->expects($this->never())->method('assertExists');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a numeric id, not "Shoes"');

        $this->arguments->applyTo(new Promotion(), ['type' => 'category', 'target' => 'Shoes'], true);
    }

    /**
     * The admin lets a category or brand id that does not exist through, and
     * the storefront then drops the promotion silently.
     *
     * @return void
     */
    public function testATargetThatDoesNotExistIsRefused(): void
    {
        $this->targetValidator->method('assertExists')
            ->willThrowException(new LocalizedException(__('No category exists with id 999.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No category exists with id 999.');

        $this->arguments->applyTo(new Promotion(), ['type' => 'category', 'target' => '999'], true);
    }

    /**
     * A new type reads the old target differently — SKU "24" and category 24
     * are different things — so the pair is checked again.
     *
     * @return void
     */
    public function testChangingOnlyTheTypeChecksTheExistingTargetUnderTheNewType(): void
    {
        $promotion = $this->promotion(['type' => 'product', 'target' => '24']);

        $this->targetValidator->expects($this->once())
            ->method('assertExists')
            ->with('category', '24');

        $this->arguments->applyTo($promotion, ['type' => 'category'], false);
    }

    /**
     * @return void
     */
    public function testAnUpdateThatLeavesTheTargetAloneDoesNotLookItUp(): void
    {
        $promotion = $this->promotion(['type' => 'product', 'target' => 'MB01']);

        $this->targetValidator->expects($this->never())->method('assertExists');

        $this->assertSame(['sort_order'], $this->arguments->applyTo($promotion, ['sort_order' => -5], false));
        $this->assertSame(-5, $promotion->getData('sort_order'));
    }

    /**
     * @return void
     */
    public function testKeywordsAreTrimmedDeduplicatedCaseInsensitivelyAndJoined(): void
    {
        $promotion = $this->promotion();

        $this->arguments->applyTo($promotion, ['keywords' => [' Samsung ', 'galaxy', 'samsung', '', '2026']], false);

        $this->assertSame('Samsung, galaxy, 2026', $promotion->getData('keywords'));
    }

    /**
     * An empty list is a general promotion, and the column is nullable for it.
     *
     * @return void
     */
    public function testNoKeywordsStoresNull(): void
    {
        $promotion = $this->promotion(['keywords' => 'old']);

        $this->arguments->applyTo($promotion, ['keywords' => []], false);

        $this->assertNull($promotion->getData('keywords'));
    }

    /**
     * The column is comma-separated; a comma inside one keyword would quietly
     * become two keywords.
     *
     * @return void
     */
    public function testAKeywordContainingACommaIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('contains a comma');

        $this->arguments->applyTo($this->promotion(), ['keywords' => ['tv, phone']], false);
    }

    /**
     * @return void
     */
    public function testKeywordsAsOneStringAreRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be an array of strings');

        $this->arguments->applyTo($this->promotion(), ['keywords' => 'tv,phone'], false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badDates(): array
    {
        return [
            'locale format' => ['03/04/2026'],
            'impossible day' => ['2026-02-30'],
            'with a time' => ['2026-03-04 10:00:00'],
        ];
    }

    /**
     * @param string $date
     * @return void
     */
    #[DataProvider('badDates')]
    public function testADateThatIsNotPlainIsoIsRefused(string $date): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a date as YYYY-MM-DD');

        $this->arguments->applyTo($this->promotion(), ['active_from' => $date], false);
    }

    /**
     * Checked against the row, not just the arguments, so moving only the end
     * date before a start date set earlier is caught.
     *
     * @return void
     */
    public function testAnEndDateBeforeTheStoredStartDateIsRefused(): void
    {
        $promotion = $this->promotion(['active_from' => '2026-06-01']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must not be before active_from');

        $this->arguments->applyTo($promotion, ['active_to' => '2026-05-31'], false);
    }

    /**
     * @return void
     */
    public function testNullClearsADate(): void
    {
        $promotion = $this->promotion(['active_to' => '2026-05-31']);

        $this->arguments->applyTo($promotion, ['active_to' => null], false);

        $this->assertNull($promotion->getData('active_to'));
    }

    /**
     * The storefront serves images only through its own /media proxy.
     *
     * @return void
     */
    public function testAnImageUrlIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not a URL');

        $this->arguments->applyTo($this->promotion(), ['image' => 'https://cdn.example.com/a.jpg'], false);
    }

    /**
     * @return void
     */
    public function testAnImagePathClimbingOutOfMediaIsRefused(): void
    {
        $this->media->expects($this->never())->method('isFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not a path under pub/media');

        $this->arguments->applyTo($this->promotion(), ['image' => 'wysiwyg/../../app/etc/env.php'], false);
    }

    /**
     * @return void
     */
    public function testAnImageThatIsNotThereIsRefused(): void
    {
        $this->media->method('isFile')->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('There is no file at pub/media/wysiwyg/missing.jpg');

        $this->arguments->applyTo($this->promotion(), ['image' => 'wysiwyg/missing.jpg'], false);
    }

    /**
     * @return void
     */
    public function testAnImagePathIsStoredRelativeToMedia(): void
    {
        $this->media->method('isFile')->with('wysiwyg/promo.jpg')->willReturn(true);
        $promotion = $this->promotion();

        $this->arguments->applyTo($promotion, ['image' => '/wysiwyg/promo.jpg'], false);

        $this->assertSame('wysiwyg/promo.jpg', $promotion->getData('image'));
    }

    /**
     * An unknown store view would otherwise fail on the foreign key, as a
     * database error rather than an answer.
     *
     * @return void
     */
    public function testAnUnknownStoreViewIsRefused(): void
    {
        $this->storeManager->method('getStore')->willThrowException(new NoSuchEntityException(__('x')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No store view exists with id 42');

        $this->arguments->applyTo($this->promotion(), ['store_id' => 42], false);
    }

    /**
     * @return void
     */
    public function testStoreZeroMeansEveryStoreViewAndIsNotLookedUp(): void
    {
        $this->storeManager->expects($this->never())->method('getStore');
        $promotion = $this->promotion(['store_id' => 1]);

        $this->arguments->applyTo($promotion, ['store_id' => 0], false);

        $this->assertSame(0, $promotion->getData('store_id'));
    }

    /**
     * @return void
     */
    public function testIsActiveAsAStringIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be true or false');

        $this->arguments->applyTo($this->promotion(), ['is_active' => 'false'], false);
    }

    /**
     * @return void
     */
    public function testAnOverlongTitleIsRefusedRatherThanTruncated(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('the column holds 255');

        $this->arguments->applyTo($this->promotion(), ['title' => str_repeat('a', 256)], false);
    }

    /**
     * @param array<string, mixed> $data
     * @return Promotion
     */
    private function promotion(array $data = []): Promotion
    {
        $promotion = new Promotion();
        foreach ($data + ['promotion_id' => 7, 'type' => 'product', 'target' => 'MB01'] as $key => $value) {
            $promotion->setData($key, $value);
        }

        return $promotion;
    }
}
