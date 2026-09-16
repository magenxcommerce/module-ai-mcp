<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Helpdesk\Lookup;

use Magenx\AiMcp\Model\Tool\Helpdesk\Lookup\SaveSpamPattern;
use Magenx\Helpdesk\Model\ResourceModel\SpamPattern as ResourceModel;
use Magenx\Helpdesk\Model\SpamPattern;
use Magenx\Helpdesk\Model\SpamPatternFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Writing the rule that decides which mail never becomes a ticket.
 *
 * A spam pattern is the one lookup whose mistake fails in the dangerous
 * direction: mail it matches is discarded rather than queued, so a rule broader
 * than intended throws away customer enquiries and leaves nothing behind to
 * show it happened. The empty-match guard is the whole reason this test exists
 * — the module's own resource model already refuses a pattern that will not
 * compile, but a pattern that compiles and matches everything is an off switch
 * for the mailbox that looks like a filter.
 *
 * @see SaveSpamPattern::validateRow
 */
class SaveSpamPatternTest extends TestCase
{
    private ResourceModel&MockObject $resource;
    private SpamPattern&MockObject $row;
    private SaveSpamPattern $tool;

    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->row = $this->createMock(SpamPattern::class);
        $this->row->method('setData')->willReturnCallback(function ($key, $value = null) {
            $this->data[$key] = $value;

            return $this->row;
        });
        $this->row->method('getData')->willReturnCallback(
            fn ($key = null, $index = null) => $this->data[$key] ?? null
        );
        $this->row->method('getId')->willReturnCallback(fn () => $this->data['pattern_id'] ?? 7);

        $factory = $this->createMock(SpamPatternFactory::class);
        $factory->method('create')->willReturn($this->row);

        $this->resource = $this->createMock(ResourceModel::class);

        $this->tool = new SaveSpamPattern($factory, $this->resource);
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheSpamResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magenx_Helpdesk::spam', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testASensiblePatternIsSaved(): void
    {
        $this->resource->expects($this->once())->method('save');

        $result = $this->tool->execute(['title' => 'Pharma', 'pattern' => '/viagra/i']);

        $this->assertTrue($result['created']);
        $this->assertSame('/viagra/i', $result['pattern']);
        $this->assertContains('pattern', $result['changed_fields']);
    }

    /**
     * The guard. An empty body between the delimiters matches every message.
     *
     * @return void
     */
    public function testAPatternMatchingEverythingIsRefusedBeforeItIsSaved(): void
    {
        $this->resource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('would discard every incoming');

        $this->tool->execute(['title' => 'Everything', 'pattern' => '//']);
    }

    /**
     * @return void
     */
    public function testADotStarPatternIsRefusedToo(): void
    {
        $this->resource->expects($this->never())->method('save');
        $this->expectException(LocalizedException::class);

        $this->tool->execute(['title' => 'Everything', 'pattern' => '/.*/']);
    }

    /**
     * A pattern where every token is optional compiles cleanly and matches the
     * empty string, so it catches everything — the subtlest form of the
     * mistake, and the reason the guard tests the pattern rather than eyeballs
     * it. Note `/spam?/` is NOT this case: only the "m" is optional, so "spa"
     * is still required and it filters what it looks like it filters.
     *
     * @return void
     */
    public function testAPatternWhereEveryTokenIsOptionalIsRefused(): void
    {
        $this->resource->expects($this->never())->method('save');
        $this->expectException(LocalizedException::class);

        $this->tool->execute(['title' => 'Oops', 'pattern' => '/s?p?a?m?/']);
    }

    /**
     * The near miss above, confirmed to pass: a partly-optional pattern is a
     * normal filter and must not be caught by the guard.
     *
     * @return void
     */
    public function testAPartlyOptionalPatternIsAccepted(): void
    {
        $this->resource->expects($this->once())->method('save');

        $this->tool->execute(['title' => 'Fine', 'pattern' => '/spam?/']);
    }

    /**
     * An uncompilable pattern falls through to the module's own check, which
     * has a better message for it than this tool could write. Passing it on
     * rather than pre-empting it is the point — two guards giving two different
     * messages for the same mistake is worse than one.
     *
     * @return void
     */
    public function testAnUncompilablePatternIsLeftToTheResourceModel(): void
    {
        $this->resource->expects($this->once())->method('save');

        $this->tool->execute(['title' => 'Missing delimiters', 'pattern' => 'viagra']);
    }

    /**
     * @return void
     */
    public function testTitleAndPatternAreRequiredWhenCreating(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"pattern" argument is required');

        $this->tool->execute(['title' => 'No pattern']);
    }

    /**
     * @return void
     */
    public function testAScopeOutsideTheKnownThreeIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be one of: subject, from, body');

        $this->tool->execute(['title' => 'X', 'pattern' => '/x/', 'scope' => 'headers']);
    }

    /**
     * Booleans are not cast, for the reason AbstractTool gives: "false" is a
     * string a model produces and casting it yields true — which here would
     * activate a rule that was meant to stay off.
     *
     * @return void
     */
    public function testAStringInsteadOfABooleanIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be true or false');

        $this->tool->execute(['title' => 'X', 'pattern' => '/x/', 'is_active' => 'false']);
    }
}
