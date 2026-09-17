<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\ProductFeed\FeedArguments;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\Template\Exception\TemplateSyntaxException;
use Magenx\ProductFeed\Model\Template\TemplateEngine;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Applying the writable fields of a product feed.
 *
 * This class carries every check the product feed module keeps in its admin
 * controller rather than in its model, so a tool writing a row directly gets
 * none of them for free. Each of those checks guards a failure that is silent
 * rather than loud, and the tests below are organised around that: what would
 * happen if this one were dropped.
 *
 * @see FeedArguments::applyTo
 */
class FeedArgumentsTest extends TestCase
{
    private TemplateEngine&MockObject $templateEngine;
    private FeedArguments $arguments;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->templateEngine = $this->createMock(TemplateEngine::class);
        $this->arguments = new FeedArguments($this->templateEngine);
    }

    /**
     * The distinction the whole rendering mode turns on.
     *
     * Runner::buildPlan() picks record mode on a non-empty field map, so a map
     * stored as "[]" is not an empty map — it is a different kind of feed. The
     * column is nullable precisely so the two can be told apart.
     *
     * @return void
     */
    public function testAnEmptyFieldMapStoresNullRatherThanAnEmptyJsonArray(): void
    {
        $feed = $this->feed(['template' => '']);

        $this->arguments->applyTo($feed, ['field_map' => []], false);

        $this->assertNull($feed->getData('field_map'));
        $this->assertNotSame('[]', $feed->getData('field_map'));
    }

    /**
     * @return void
     */
    public function testAFieldMapIsStoredAsJsonWithOnlyColumnAndValue(): void
    {
        $feed = $this->feed(['template' => '']);

        $this->arguments->applyTo($feed, [
            'field_map' => [
                ['column' => 'sku', 'value' => '{{ product.sku }}', 'extra' => 'ignored'],
                ['column' => 'title'],
            ],
        ], false);

        $this->assertSame(
            [
                ['column' => 'sku', 'value' => '{{ product.sku }}'],
                ['column' => 'title', 'value' => ''],
            ],
            json_decode((string) $feed->getData('field_map'), true)
        );
    }

    /**
     * The admin drops a blank row silently because a trailing empty row is a
     * normal artefact of its grid widget. Nothing here has a grid, so a blank
     * column is a mistake and saying so beats dropping it.
     *
     * @return void
     */
    public function testABlankColumnIsRefusedRatherThanDropped(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('needs a non-empty "column"');

        $this->arguments->applyTo(
            $this->feed(['template' => '']),
            ['field_map' => [['column' => '   ', 'value' => 'x']]],
            false
        );
    }

    /**
     * The refusal that is not in the admin at all.
     *
     * A feed carrying both is treated as template-driven and its field map is
     * ignored — deliberately, upstream, and with no error. Through an API that
     * means writing columns, getting a clean save, and finding none of them in
     * the file.
     *
     * @return void
     */
    public function testATemplateAndAFieldMapTogetherAreRefusedWithBothWaysOut(): void
    {
        $feed = $this->feed(['template' => '<rss>{{ product.sku }}</rss>']);

        try {
            $this->arguments->applyTo($feed, ['field_map' => [['column' => 'sku']]], false);
            $this->fail('A feed with both a template and a field map must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('the field map would be ignored', $e->getMessage());
            $this->assertStringContainsString('"template" as an empty string', $e->getMessage());
            $this->assertStringContainsString('"field_map" as []', $e->getMessage());
        }
    }

    /**
     * The state is checked on the resulting row, not on the arguments, so
     * adding a map to a feed that already has a template is caught — which is
     * the likely way in.
     *
     * @return void
     */
    public function testClearingTheTemplateInTheSameCallMakesAFieldMapAllowed(): void
    {
        $feed = $this->feed(['template' => '<rss/>']);

        $changed = $this->arguments->applyTo($feed, [
            'template' => '',
            'field_map' => [['column' => 'sku', 'value' => '{{ product.sku }}']],
        ], false);

        $this->assertContains('field_map', $changed);
        $this->assertSame('', $feed->getData('template'));
    }

    /**
     * @return void
     */
    public function testScheduleArraysAreStoredAsCommaSeparatedStrings(): void
    {
        $feed = $this->feed(['template' => '']);

        $this->arguments->applyTo($feed, [
            'schedule_days' => [5, 1, 1],
            'schedule_times' => ['18:00', '06:30'],
        ], false);

        // varchar columns the module splits on commas, not arrays.
        $this->assertSame('1,5', $feed->getData('schedule_days'));
        $this->assertSame('06:30,18:00', $feed->getData('schedule_times'));
    }

    /**
     * @return void
     */
    public function testAWeekdayOutsideOneToSevenIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('between 1 (Monday) and 7 (Sunday)');

        $this->arguments->applyTo($this->feed(['template' => '']), ['schedule_days' => [0]], false);
    }

    /**
     * @return void
     */
    public function testAMalformedTimeIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('24-hour time');

        $this->arguments->applyTo(
            $this->feed(['template' => '']),
            ['schedule_times' => ['6pm']],
            false
        );
    }

    /**
     * A value that is not a string gets its own refusal, because it cannot be
     * quoted back into the message safely — which is what the original single
     * check needed a type-name function for, and what CI's Magento standard
     * refused.
     *
     * @return void
     */
    public function testANonStringScheduleTimeIsRefusedWithoutEchoingIt(): void
    {
        try {
            $this->arguments->applyTo($this->feed(['template' => '']), ['schedule_times' => [630]], false);
            $this->fail('A non-string schedule time must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('must be a string in 24-hour form', $e->getMessage());
            // The offending value is deliberately not interpolated here.
            $this->assertStringNotContainsString('630', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testATwentyFourHourTimeIsRefusedAsOutOfRange(): void
    {
        $this->expectException(LocalizedException::class);

        $this->arguments->applyTo(
            $this->feed(['template' => '']),
            ['schedule_times' => ['24:00']],
            false
        );
    }

    /**
     * NOT NULL columns, so a create that never mentions them still has to write
     * something rather than leaving the insert to fail.
     *
     * @return void
     */
    public function testCreateDefaultsTheNotNullFlagsToZero(): void
    {
        $feed = new Feed();

        $this->arguments->applyTo($feed, [
            'name' => 'Google',
            'format' => 'csv',
            'filename' => 'google.csv',
        ], true);

        foreach (['is_active', 'csv_include_header', 'csv_bom'] as $key) {
            $this->assertSame(0, $feed->getData($key), $key);
        }
    }

    /**
     * @return void
     */
    public function testBooleansAreStoredAsIntegers(): void
    {
        $feed = $this->feed(['template' => '']);

        $this->arguments->applyTo($feed, ['is_active' => true, 'csv_bom' => false], false);

        $this->assertSame(1, $feed->getData('is_active'));
        $this->assertSame(0, $feed->getData('csv_bom'));
    }

    /**
     * @return void
     */
    public function testAStringInsteadOfABooleanIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be true or false');

        $this->arguments->applyTo($this->feed(['template' => '']), ['is_active' => 'true'], false);
    }

    /**
     * Left to the module an unknown code falls back silently to a comma, so a
     * feed asked for pipes would publish with commas and nothing would say so.
     *
     * @return void
     */
    public function testAnUnknownDelimiterCodeIsRefusedNamingTheValidOnes(): void
    {
        try {
            $this->arguments->applyTo($this->feed(['template' => '']), ['csv_delimiter' => '|'], false);
            $this->fail('An unknown delimiter code must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('pipe', $e->getMessage());
            $this->assertStringContainsString('semicolon', $e->getMessage());
            // The point of the message: these are names, not characters.
            $this->assertStringContainsString('not the characters', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testAValidDelimiterCodeIsAccepted(): void
    {
        $feed = $this->feed(['template' => '']);

        $this->arguments->applyTo($feed, ['csv_delimiter' => 'pipe'], false);

        $this->assertSame('pipe', $feed->getData('csv_delimiter'));
    }

    /**
     * @return void
     */
    public function testAnUnknownFormatIsRefusedNamingTheValidOnes(): void
    {
        try {
            $this->arguments->applyTo($this->feed(['template' => '']), ['format' => 'yaml'], false);
            $this->fail('An unknown format must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('xml', $e->getMessage());
            $this->assertStringContainsString('jsonl', $e->getMessage());
        }
    }

    /**
     * The check the admin makes for a reason it states: an uncompilable
     * template stored now fails hours later inside cron, far from whoever
     * wrote it.
     *
     * @return void
     */
    public function testATemplateThatDoesNotCompileIsRefusedBeforeAnythingIsStored(): void
    {
        $this->templateEngine->method('compile')
            ->willThrowException(new TemplateSyntaxException('Unexpected end of input'));

        try {
            $this->arguments->applyTo($this->feed([]), ['template' => '{{ product.sku'], false);
            $this->fail('An uncompilable template must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('template does not compile', $e->getMessage());
            $this->assertStringContainsString('Unexpected end of input', $e->getMessage());
        }
    }

    /**
     * A plain file name is not a template, and running one through the engine
     * would make ordinary punctuation a syntax error.
     *
     * @return void
     */
    public function testAPlainFilenameIsNotCompiled(): void
    {
        $this->templateEngine->expects($this->never())->method('compile');

        $this->arguments->applyTo($this->feed(['template' => '']), ['filename' => 'google.csv'], false);
    }

    /**
     * @return void
     */
    public function testAFilenameCarryingAPlaceholderIsCompiled(): void
    {
        $this->templateEngine->expects($this->once())
            ->method('compile')
            ->with('products-{{ context.date }}.csv');

        $this->arguments->applyTo(
            $this->feed(['template' => '']),
            ['filename' => 'products-{{ context.date }}.csv'],
            false
        );
    }

    /**
     * @return void
     */
    public function testEveryFieldMapValueIsCompiled(): void
    {
        $compiled = [];
        $this->templateEngine->method('compile')->willReturnCallback(
            static function (string $source) use (&$compiled): array {
                $compiled[] = $source;

                return [];
            }
        );

        $this->arguments->applyTo($this->feed(['template' => '']), [
            'field_map' => [
                ['column' => 'sku', 'value' => '{{ product.sku }}'],
                ['column' => 'price', 'value' => '{{ product.price }}'],
                ['column' => 'blank', 'value' => ''],
            ],
        ], false);

        $this->assertSame(['{{ product.sku }}', '{{ product.price }}'], $compiled);
    }

    /**
     * @return void
     */
    public function testAFieldMapValueThatDoesNotCompileNamesItsColumn(): void
    {
        $this->templateEngine->method('compile')
            ->willThrowException(new TemplateSyntaxException('bad'));

        try {
            $this->arguments->applyTo($this->feed(['template' => '']), [
                'field_map' => [['column' => 'price', 'value' => '{{ oops']],
            ], false);
            $this->fail('An uncompilable field map value must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('column "price"', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testCreateRequiresNameFormatAndFilename(): void
    {
        $incompleteCreates = [
            ['format' => 'csv', 'filename' => 'f.csv'],
            ['name' => 'Google', 'filename' => 'f.csv'],
            ['name' => 'Google', 'format' => 'csv'],
        ];

        foreach ($incompleteCreates as $incomplete) {
            try {
                $this->arguments->applyTo(new Feed(), $incomplete, true);
                $this->fail('An incomplete create must be refused: ' . json_encode($incomplete));
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('required', $e->getMessage());
            }
        }
    }

    /**
     * The trap the feed model documents: AbstractModel::loadPost() casts these
     * two keys and only these two into DateTime objects, after which any string
     * cast of them fatals. The columns do not exist and must not be invented.
     *
     * @return void
     */
    public function testTheDateFieldsAreNeitherOfferedNorWritten(): void
    {
        $this->assertArrayNotHasKey('from_date', $this->arguments->schemaProperties());
        $this->assertArrayNotHasKey('to_date', $this->arguments->schemaProperties());

        $feed = $this->feed(['template' => '']);
        $this->arguments->applyTo($feed, ['from_date' => '2026-01-01', 'to_date' => '2026-12-31'], false);

        $this->assertNull($feed->getData('from_date'));
        $this->assertNull($feed->getData('to_date'));
    }

    /**
     * Run state is written by the export straight to the row while it runs.
     * Nothing here may offer it.
     *
     * @return void
     */
    public function testRunStateIsNotWritable(): void
    {
        $properties = $this->arguments->schemaProperties();

        $runState = [
            'status',
            'cursor_position',
            'last_filename',
            'last_generated_at',
            'last_error',
            'product_count',
            'generation_time',
            'url_secret',
        ];

        foreach ($runState as $key) {
            $this->assertArrayNotHasKey($key, $properties, $key);
        }
    }

    /**
     * Conditions are Magento Rule machinery needing loadPost(), the same call
     * made for the price rule tools.
     *
     * @return void
     */
    public function testConditionsAreNotWritable(): void
    {
        $this->assertArrayNotHasKey('conditions_serialized', $this->arguments->schemaProperties());
    }

    /**
     * @return void
     */
    public function testOnlyTheFieldsPassedAreReportedAsChanged(): void
    {
        $feed = $this->feed(['template' => '', 'name' => 'Old']);

        $changed = $this->arguments->applyTo($feed, ['name' => 'New'], false);

        $this->assertSame(['name'], $changed);
    }

    /**
     * @param array<string, mixed> $data
     * @return Feed
     */
    private function feed(array $data): Feed
    {
        $feed = new Feed();
        $feed->setData('feed_id', 3);
        foreach ($data as $key => $value) {
            $feed->setData($key, $value);
        }

        return $feed;
    }
}
