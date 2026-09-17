<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\AdminActivity;

use Magenx\AdminActivity\Model\Activity;
use Magenx\AdminActivity\Model\Config;
use Magenx\AdminActivity\Model\ResourceModel\Activity\Collection;
use Magenx\AdminActivity\Model\ResourceModel\Activity\CollectionFactory;
use Magenx\AiMcp\Model\Tool\AdminActivity\ActivityProjector;
use Magenx\AiMcp\Model\Tool\AdminActivity\SearchAdminActivity;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Searching what admin users did.
 *
 * Two failure modes here are worse than a wrong answer, because both look like
 * a right one. A filter value the log has never heard of would otherwise return
 * an empty page, which an agent reports as "nobody did this"; and an empty page
 * from a store that has logging switched off reads identically to an empty page
 * from a store where nothing happened. Both are pinned below.
 *
 * @see SearchAdminActivity
 */
class SearchAdminActivityTest extends TestCase
{
    private Collection&MockObject $collection;
    private Config&MockObject $config;
    private SearchAdminActivity $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection);

        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);

        $this->tool = new SearchAdminActivity($factory, new ActivityProjector(), $this->config);
    }

    /**
     * @return void
     */
    public function testItIsAReadBehindTheActivityLogsOwnResource(): void
    {
        $this->assertFalse($this->tool->isWrite());
        $this->assertSame('Magenx_AdminActivity::activity', $this->tool->getAclResource());
    }

    /**
     * An action type the log cannot contain is refused, not filtered on.
     *
     * @return void
     */
    public function testAnUnknownActionTypeIsRefusedRatherThanReturningNothing(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown "action_type" value "modified".');

        $this->tool->execute(['action_type' => 'modified']);
    }

    /**
     * The refusal lists what the log does record, so the next call is right.
     *
     * @return void
     */
    public function testTheRefusalNamesTheValuesThatExist(): void
    {
        try {
            $this->tool->execute(['status' => 'partial']);
            $this->fail('An unknown status must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('success, failure', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testAKnownActionTypeIsFilteredOn(): void
    {
        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('action_type', 'delete');

        $this->tool->execute(['action_type' => 'delete']);
    }

    /**
     * "No results" and "nothing is being recorded" are different answers.
     *
     * @return void
     */
    public function testAResultSaysWhetherLoggingIsEvenOn(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection);

        $tool = new SearchAdminActivity($factory, new ActivityProjector(), $config);

        $this->assertFalse($tool->execute([])['logging_enabled']);
    }

    /**
     * Results are fed to a model, so nothing may ask for an unbounded page.
     *
     * @return void
     */
    public function testAnOversizedPageIsClampedToTheMaximum(): void
    {
        $this->collection->expects($this->once())->method('setPageSize')->with(100);

        $this->assertSame(100, $this->tool->execute(['page_size' => 5000])['page_size']);
    }

    /**
     * Newest first is what an audit question almost always wants.
     *
     * @return void
     */
    public function testTheDefaultSortIsNewestFirst(): void
    {
        $this->collection->expects($this->once())->method('setOrder')->with('created_at', 'DESC');

        $this->tool->execute([]);
    }

    /**
     * The summary is a whitelist, and the two long columns are not on it.
     *
     * @return void
     */
    public function testTheSummaryOmitsTheLongRepetitiveColumns(): void
    {
        $this->collection->method('getSize')->willReturn(1);
        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->activity([
                'activity_id' => '42',
                'username' => 'jane.support',
                'action_type' => 'edit',
                'request_url' => 'https://example.com/admin/catalog/product/edit/id/1',
                'user_agent' => 'Mozilla/5.0 (a very long string indeed)',
            ]),
        ]));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $row = (new SearchAdminActivity($factory, new ActivityProjector(), $this->config))
            ->execute([])['items'][0];

        $this->assertSame(42, $row['activity_id']);
        $this->assertArrayNotHasKey('request_url', $row);
        $this->assertArrayNotHasKey('user_agent', $row);
    }

    /**
     * @param array<string, mixed> $data
     * @return Activity&MockObject
     */
    private function activity(array $data): Activity&MockObject
    {
        $activity = $this->createMock(Activity::class);
        $activity->method('getData')->willReturnCallback(
            static fn (?string $key = null, $index = null) => $data[$key] ?? null
        );

        return $activity;
    }
}
