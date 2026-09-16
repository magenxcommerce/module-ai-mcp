<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\AdminActivity;

use Magenx\AdminActivity\Model\Activity;
use Magenx\AdminActivity\Model\ActivityDetail;
use Magenx\AiMcp\Model\Tool\AdminActivity\ActivityProjector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Projecting one admin action and the fields it changed.
 *
 * The value columns hold up to 128 KB each, so they have to be clipped before
 * they reach a model. The risk that creates is the whole reason this test
 * exists: a value silently cut in half still reads as a complete value, and
 * this tool is asked precisely what a field changed *from* — so an answer that
 * looks whole and is not is worse than no answer. The truncation must announce
 * itself.
 *
 * @see ActivityProjector
 */
class ActivityProjectorTest extends TestCase
{
    private ActivityProjector $projector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->projector = new ActivityProjector();
    }

    /**
     * @return void
     */
    public function testALongValueSaysThatItWasTruncated(): void
    {
        $long = str_repeat('x', 5000);

        $change = $this->projector->toDetail(
            $this->activity(['activity_id' => '1']),
            [$this->detail('description', $long, 'short')]
        )['changes'][0];

        $this->assertStringContainsString('(truncated, 5000 characters total)', $change['old_value']);
        $this->assertLessThan(strlen($long), strlen($change['old_value']));
    }

    /**
     * A value that fits is returned byte for byte — a projector that reformatted
     * every value would make an audit trail useless for comparing them.
     *
     * @return void
     */
    public function testAShortValueSurvivesUntouched(): void
    {
        $change = $this->projector->toDetail(
            $this->activity(['activity_id' => '1']),
            [$this->detail('price', '19.9900', '9.9900')]
        )['changes'][0];

        $this->assertSame('19.9900', $change['old_value']);
        $this->assertSame('9.9900', $change['new_value']);
    }

    /**
     * A field that had no previous value is not the same as one that was blank.
     *
     * @return void
     */
    public function testANullValueStaysNullRatherThanBecomingAnEmptyString(): void
    {
        $change = $this->projector->toDetail(
            $this->activity(['activity_id' => '1']),
            [$this->detail('meta_title', null, 'Autumn sale')]
        )['changes'][0];

        $this->assertNull($change['old_value']);
    }

    /**
     * The username is stored denormalised so the row stays readable after the
     * admin user is deleted, which is when the trail matters most. The id is
     * null on such a row and must not be reported as 0.
     *
     * @return void
     */
    public function testADeletedUsersNameSurvivesWithoutAnId(): void
    {
        $row = $this->projector->toSummary($this->activity([
            'activity_id' => '8',
            'username' => 'former.admin',
            'user_id' => null,
        ]));

        $this->assertSame('former.admin', $row['username']);
        $this->assertNull($row['user_id']);
    }

    /**
     * @return void
     */
    public function testTheDetailAddsTheColumnsTheSummaryLeavesOut(): void
    {
        $detail = $this->projector->toDetail(
            $this->activity(['activity_id' => '3', 'request_url' => '/admin/x', 'user_agent' => 'curl/8']),
            []
        );

        $this->assertSame('/admin/x', $detail['request_url']);
        $this->assertSame('curl/8', $detail['user_agent']);
        $this->assertSame([], $detail['changes']);
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

    /**
     * @param string $field
     * @param string|null $old
     * @param string|null $new
     * @return ActivityDetail&MockObject
     */
    private function detail(string $field, ?string $old, ?string $new): ActivityDetail&MockObject
    {
        $data = ['field_name' => $field, 'old_value' => $old, 'new_value' => $new];

        $detail = $this->createMock(ActivityDetail::class);
        $detail->method('getData')->willReturnCallback(
            static fn (?string $key = null, $index = null) => $data[$key] ?? null
        );

        return $detail;
    }
}
