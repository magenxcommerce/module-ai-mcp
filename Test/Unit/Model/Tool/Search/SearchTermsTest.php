<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Search;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\Search\SearchTerms;
use Magento\Framework\DataObject;
use Magento\Search\Model\ResourceModel\Query\Collection;
use Magento\Search\Model\ResourceModel\Query\CollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * @see SearchTerms
 */
class SearchTermsTest extends TestCase
{
    /** @var array<int, array{0: mixed, 1: mixed}> */
    private array $filters = [];

    /**
     * The default answer is the gaps: terms that found nothing.
     *
     * @return void
     */
    public function testZeroResultTermsAreTheDefault(): void
    {
        $this->tool()->execute([]);

        $this->assertContains(['num_results', 0], $this->filters);
    }

    /**
     * @return void
     */
    public function testEveryTermCanBeAskedFor(): void
    {
        $this->tool()->execute(['zero_results_only' => false, 'store_code' => 'en']);

        $this->assertNotContains(['num_results', 0], $this->filters);
        $this->assertContains(['store_id', 3], $this->filters);
    }

    /**
     * @return void
     */
    public function testATermIsProjectedWithItsCounts(): void
    {
        $result = $this->tool([
            new DataObject([
                'query_id' => '9',
                'query_text' => 'blue sofa',
                'popularity' => '41',
                'num_results' => '0',
                'store_id' => '1',
                'updated_at' => '2026-03-14 10:00:00',
            ]),
        ])->execute([]);

        $this->assertSame(1, $result['total_count']);
        $this->assertSame('blue sofa', $result['items'][0]['query_text']);
        $this->assertSame(41, $result['items'][0]['popularity']);
        $this->assertNull($result['items'][0]['redirect']);
    }

    /**
     * @param DataObject[] $terms
     * @return SearchTerms
     */
    private function tool(array $terms = []): SearchTerms
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use ($collection) {
            $this->filters[] = [$field, $condition];

            return $collection;
        });
        $collection->method('getSize')->willReturn(count($terms));
        $collection->method('getIterator')->willReturn(new \ArrayIterator($terms));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $stores = $this->createMock(StoreResolver::class);
        $stores->method('resolve')->willReturn(3);

        return new SearchTerms($factory, $stores);
    }
}
