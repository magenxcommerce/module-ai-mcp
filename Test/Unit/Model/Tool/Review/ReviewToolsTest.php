<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Review;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\Review\GetReview;
use Magenx\AiMcp\Model\Tool\Review\ReviewLocator;
use Magenx\AiMcp\Model\Tool\Review\ReviewProjector;
use Magenx\AiMcp\Model\Tool\Review\SearchReviews;
use Magenx\AiMcp\Model\Tool\Review\SetReviewStatus;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory;
use Magento\Review\Model\Review;
use PHPUnit\Framework\TestCase;

/**
 * Reading and moderating product reviews.
 */
class ReviewToolsTest extends TestCase
{
    /**
     * @return void
     */
    public function testStatusIsFilteredByMagentosIdAndReportedByName(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addStatusFilter')->with(2)->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$this->review(2)]));

        $result = $this->search($collection)->execute(['status' => 'pending']);

        $this->assertSame('pending', $result['items'][0]['status']);
        $this->assertSame(17, $result['items'][0]['product_id']);
        $this->assertSame([['rating' => 'Quality', 'value' => 4, 'percent' => 80]], $result['items'][0]['ratings']);
    }

    /**
     * @return void
     */
    public function testAnUnknownSkuIsRefusedRatherThanListingEveryReview(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no product with sku "nope"');

        $this->search($this->createMock(Collection::class))->execute(['sku' => 'nope']);
    }

    /**
     * @return void
     */
    public function testAReviewTextIsCutAtTwoThousandCharacters(): void
    {
        $projected = (new ReviewProjector())->toArray($this->review(1, str_repeat('a', 2500)), []);

        $this->assertSame(2001, mb_strlen($projected['detail']));
    }

    /**
     * @return void
     */
    public function testGetReviewAddsItsStoreViews(): void
    {
        $source = $this->review(1, 'Nice', ['1', '2']);
        $review = $this->createMock(Review::class);
        $review->method('getId')->willReturn(5);
        $review->method('getData')->willReturnCallback(static fn ($key) => $source->getData($key));

        $locator = $this->createMock(ReviewLocator::class);
        $locator->method('get')->willReturn($review);
        $locator->method('votes')->willReturn([]);

        $result = (new GetReview($locator, new ReviewProjector()))->execute(['review_id' => 5]);

        $this->assertSame('approved', $result['status']);
        $this->assertSame([1, 2], $result['store_ids']);
    }

    /**
     * The rating summary is re-aggregated after the save, so the product's
     * stars move with the decision.
     *
     * @return void
     */
    public function testSettingAStatusSavesAndReaggregates(): void
    {
        $review = $this->createMock(Review::class);
        $review->method('getData')->with('status_id')->willReturn('2');
        $review->expects($this->once())->method('setData')->with('status_id', 1);
        $review->expects($this->once())->method('aggregate');

        $locator = $this->createMock(ReviewLocator::class);
        $locator->method('get')->willReturn($review);
        $locator->expects($this->once())->method('save')->with($review);

        $tool = new SetReviewStatus($locator);
        $result = $tool->execute(['review_id' => 5, 'status' => 'approved']);

        $this->assertSame(['review_id' => 5, 'previous_status' => 'pending', 'status' => 'approved'], $result);
        $this->assertTrue($tool->isWrite());
        $this->assertTrue($tool->getAnnotations()['idempotentHint']);
    }

    /**
     * @return void
     */
    public function testAnUnknownStatusIsRefusedBeforeAnythingIsLoaded(): void
    {
        $locator = $this->createMock(ReviewLocator::class);
        $locator->expects($this->never())->method('get');
        $this->expectException(LocalizedException::class);

        (new SetReviewStatus($locator))->execute(['review_id' => 5, 'status' => 'hidden']);
    }

    /**
     * @param Collection $collection
     * @return SearchReviews
     */
    private function search(Collection $collection): SearchReviews
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new SearchReviews(
            $factory,
            $this->createMock(ProductResource::class),
            $this->createMock(StoreResolver::class),
            new ReviewProjector()
        );
    }

    /**
     * @param int $statusId
     * @param string $detail
     * @param string[] $stores
     * @return object
     */
    private function review(int $statusId, string $detail = 'Nice', array $stores = []): object
    {
        return new class ($statusId, $detail, $stores) extends DataObject {
            /**
             * @param int $statusId
             * @param string $detail
             * @param string[] $stores
             */
            public function __construct(int $statusId, string $detail, array $stores)
            {
                parent::__construct([
                    'id' => 5,
                    'status_id' => (string) $statusId,
                    'entity_pk_value' => '17',
                    'title' => 'Good',
                    'detail' => $detail,
                    'nickname' => 'Ann',
                    'customer_id' => null,
                    'store_id' => '1',
                    'created_at' => '2026-03-01 10:00:00',
                    'stores' => $stores,
                ]);
            }

            /**
             * @return DataObject[]
             */
            public function getRatingVotes(): array
            {
                return [new DataObject(['rating_code' => 'Quality', 'value' => '4', 'percent' => '80'])];
            }
        };
    }
}
