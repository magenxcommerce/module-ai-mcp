<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\ProductFeed\Model\Config\Source\Delimiter;
use Magenx\ProductFeed\Model\Config\Source\Enclosure;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\Template\Exception\TemplateSyntaxException;
use Magenx\ProductFeed\Model\Template\TemplateEngine;
use Magento\Framework\Exception\LocalizedException;

/**
 * Applies the writable fields of a feed, with the checks the admin does.
 *
 * This class exists because of where the product feed module puts its
 * validation. Every bit of it — the template compile, the field-map
 * normalisation, the schedule joins, the boolean coercions — lives in
 * `Controller/Adminhtml/Feed/Save.php`, not in the model or the resource model.
 * A tool that built a row and saved it would get none of it, and the module
 * would accept the result without complaint.
 *
 * What that costs, concretely:
 *
 *  - An uncompilable template saves fine and then fails hours later inside
 *    cron, with nobody watching. The admin controller compiles first for
 *    exactly that reason and so does this.
 *  - `field_map` stored as `'[]'` rather than `null` is not the same feed:
 *    `Runner::buildPlan()` picks record mode on a non-empty map, so the
 *    difference silently switches the whole rendering mode.
 *  - `schedule_days` and `schedule_times` are comma-separated strings in
 *    varchar columns. An array posted straight at them corrupts the row.
 *  - `is_active`, `csv_include_header` and `csv_bom` are NOT NULL integers.
 *
 * Nothing here writes the row's run state — `status`, `cursor_position`,
 * `last_filename`, `last_generated_at`, `last_error`, `product_count`,
 * `generation_time`, `url_secret`. The export writes those straight to the row
 * as it goes, rather than saving the model, precisely so it need not re-save a
 * whole feed inside its loop. This class only ever sets the fields it is asked
 * for, so it cannot write them itself — but saving a feed loaded before a run
 * started would still put the pre-run values back over them, which is why
 * update_feed refuses while a run is in progress rather than relying on this.
 *
 * Two fields are deliberately absent from every schema here.
 * `conditions_serialized` is Magento Rule machinery that needs `loadPost()`
 * with the admin form's nested post array — the same call made for the price
 * rule tools. And `from_date`/`to_date` must never be set at all: the feed
 * model's own docblock records that `AbstractModel::loadPost()` casts exactly
 * those two keys to DateTime, after which any string cast of them fatals.
 */
class FeedArguments
{
    /** Delimiter codes the module accepts. They are codes, not the characters. */
    private const DELIMITERS = [
        Delimiter::COMMA,
        Delimiter::SEMICOLON,
        Delimiter::TAB,
        Delimiter::PIPE,
        Delimiter::COLON,
        Delimiter::SPACE,
    ];

    /** Enclosure codes the module accepts. */
    private const ENCLOSURES = [Enclosure::DOUBLE, Enclosure::SINGLE, Enclosure::NONE];

    /** The four output formats, in the order the admin lists them. */
    private const FORMATS = [Feed::FORMAT_XML, Feed::FORMAT_CSV, Feed::FORMAT_TSV, Feed::FORMAT_JSONL];

    /**
     * @param TemplateEngine $templateEngine
     */
    public function __construct(private readonly TemplateEngine $templateEngine)
    {
    }

    /**
     * @param Feed $feed
     * @param array<string, mixed> $arguments
     * @param bool $isCreate
     * @return string[] The field names actually changed.
     * @throws LocalizedException
     */
    public function applyTo(Feed $feed, array $arguments, bool $isCreate): array
    {
        $changed = array_merge(
            $this->applyStrings($feed, $arguments, $isCreate),
            $this->applyFormat($feed, $arguments, $isCreate),
            $this->applyBooleans($feed, $arguments, $isCreate),
            $this->applyCsvOptions($feed, $arguments),
            $this->applySchedule($feed, $arguments),
            $this->applyFieldMap($feed, $arguments),
            $this->applyValidationRules($feed, $arguments)
        );

        // Checked against the resulting row rather than the arguments, so that
        // adding a field map to a feed that already has a template is caught —
        // which is the likely way into this state.
        $this->assertOneRenderMode($feed);
        $this->assertTemplatesCompile($feed);

        return $changed;
    }

    /**
     * The properties both create and update share.
     *
     * `store_id` is not among them: update omits it, because the published file
     * lives under the store's own directory and moving a feed between stores
     * would orphan that file while the row pointed elsewhere.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'name' => ['type' => 'string', 'description' => 'What the feed is called in the admin.'],
            'description' => ['type' => 'string', 'description' => 'Internal note about the feed.'],
            'format' => [
                'type' => 'string',
                'enum' => self::FORMATS,
                'description' => 'Output format. Only csv, tsv and jsonl can be pushed to a '
                    . 'catalog API such as Meta\'s; xml can only ever be delivered as a file.',
            ],
            'filename' => [
                'type' => 'string',
                'description' => 'Name of the published file. May itself be a template, e.g. '
                    . '"products-{{ context.date }}.csv", so the file can be date-stamped.',
            ],
            'marketplace' => [
                'type' => 'string',
                'description' => 'Which marketplace this feed is aimed at, e.g. "google" or '
                    . '"meta". A label only — it does not change how the feed is delivered.',
            ],
            'template' => [
                'type' => 'string',
                'description' => 'The feed body, in the module\'s template language. Leave this '
                    . 'empty and set field_map instead to build a record-per-line feed. A feed '
                    . 'cannot have both.',
            ],
            'field_map' => [
                'type' => 'array',
                'description' => 'Columns of a record-per-line feed, in order. Pass [] to clear '
                    . 'the map. Cannot be combined with a template.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'column' => ['type' => 'string', 'description' => 'Column heading.'],
                        'value' => [
                            'type' => 'string',
                            'description' => 'Template expression producing the cell, e.g. '
                                . '"{{ product.sku }}".',
                        ],
                    ],
                    'required' => ['column'],
                    'additionalProperties' => false,
                ],
            ],
            'validation_rules' => [
                'type' => 'array',
                'description' => 'Rules checked against the generated feed. Pass [] to clear them.',
                'items' => ['type' => 'object'],
            ],
            'csv_delimiter' => [
                'type' => 'string',
                'enum' => self::DELIMITERS,
                'description' => 'Field separator for csv and tsv, named rather than given as a '
                    . 'character.',
            ],
            'csv_enclosure' => [
                'type' => 'string',
                'enum' => self::ENCLOSURES,
                'description' => 'How csv and tsv values are quoted.',
            ],
            'csv_include_header' => [
                'type' => 'boolean',
                'description' => 'Whether the first line names the columns.',
            ],
            'csv_bom' => [
                'type' => 'boolean',
                'description' => 'Whether to write a byte-order mark. Some spreadsheet software '
                    . 'needs it to read UTF-8 correctly.',
            ],
            'schedule_days' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Days the feed regenerates, as ISO weekday numbers where 1 is '
                    . 'Monday and 7 is Sunday. Pass [] for no schedule.',
            ],
            'schedule_times' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Times of day the feed regenerates, as "HH:MM" in the feed '
                    . 'store\'s timezone. Pass [] for no schedule.',
            ],
            'is_active' => [
                'type' => 'boolean',
                'description' => 'Whether cron regenerates this feed on its schedule. An inactive '
                    . 'feed can still be generated on demand with generate_feed.',
            ],
        ];
    }

    /**
     * @param Feed $feed
     * @param array<string, mixed> $arguments
     * @param bool $isCreate
     * @return string[]
     * @throws LocalizedException
     */
    private function applyStrings(Feed $feed, array $arguments, bool $isCreate): array
    {
        $changed = [];

        foreach (['name', 'description', 'filename', 'marketplace', 'template'] as $key) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            if (!is_string($arguments[$key])) {
                throw new LocalizedException(__('The "%1" argument must be a string.', $key));
            }
            $feed->setData($key, $arguments[$key]);
            $changed[] = $key;
        }

        if ($isCreate) {
            foreach (['name', 'filename'] as $key) {
                if (trim((string) $feed->getData($key)) === '') {
                    throw new LocalizedException(__('The "%1" argument is required.', $key));
                }
            }
        }

        return $changed;
    }

    /**
     * @param Feed $feed
     * @param array<string, mixed> $arguments
     * @param bool $isCreate
     * @return string[]
     * @throws LocalizedException
     */
    private function applyFormat(Feed $feed, array $arguments, bool $isCreate): array
    {
        if (!array_key_exists('format', $arguments)) {
            if ($isCreate) {
                throw new LocalizedException(__(
                    'The "format" argument is required and must be one of: %1.',
                    implode(', ', self::FORMATS)
                ));
            }

            return [];
        }

        $format = $arguments['format'];
        if (!is_string($format) || !in_array($format, self::FORMATS, true)) {
            throw new LocalizedException(__(
                'The "format" argument must be one of: %1.',
                implode(', ', self::FORMATS)
            ));
        }

        $feed->setData('format', $format);

        return ['format'];
    }

    /**
     * @param Feed $feed
     * @param array<string, mixed> $arguments
     * @param bool $isCreate
     * @return string[]
     * @throws LocalizedException
     */
    private function applyBooleans(Feed $feed, array $arguments, bool $isCreate): array
    {
        $changed = [];

        foreach (['is_active', 'csv_include_header', 'csv_bom'] as $key) {
            if (!array_key_exists($key, $arguments)) {
                // NOT NULL columns with no database default worth relying on, so
                // a create that does not mention them has to write something.
                if ($isCreate && $feed->getData($key) === null) {
                    $feed->setData($key, 0);
                }
                continue;
            }
            if (!is_bool($arguments[$key])) {
                throw new LocalizedException(
                    __('The "%1" argument must be true or false, not a string or a number.', $key)
                );
            }
            $feed->setData($key, (int) $arguments[$key]);
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * @param Feed $feed
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyCsvOptions(Feed $feed, array $arguments): array
    {
        $changed = [];

        $allowed = ['csv_delimiter' => self::DELIMITERS, 'csv_enclosure' => self::ENCLOSURES];
        foreach ($allowed as $key => $codes) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if (!is_string($value) || !in_array($value, $codes, true)) {
                // Left to the module an unknown code falls back silently to a
                // comma or a double quote, so a feed asked for pipes would be
                // published with commas and nothing would say so.
                throw new LocalizedException(__(
                    'The "%1" argument must be one of: %2. These are names, not the characters '
                    . 'themselves.',
                    $key,
                    implode(', ', $codes)
                ));
            }
            $feed->setData($key, $value);
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * @param Feed $feed
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applySchedule(Feed $feed, array $arguments): array
    {
        $changed = [];

        if (array_key_exists('schedule_days', $arguments)) {
            $days = [];
            foreach ($this->requireArray($arguments, 'schedule_days') as $day) {
                if (!is_int($day) && !(is_string($day) && ctype_digit($day))) {
                    throw new LocalizedException(
                        __('Every entry in "schedule_days" must be a whole number.')
                    );
                }
                $day = (int) $day;
                if ($day < 1 || $day > 7) {
                    throw new LocalizedException(__(
                        'Every entry in "schedule_days" must be between 1 (Monday) and 7 (Sunday); '
                        . 'got %1.',
                        $day
                    ));
                }
                $days[] = $day;
            }
            sort($days);
            // A varchar column, not a set: the module splits it on commas.
            $feed->setData('schedule_days', implode(',', array_unique($days)));
            $changed[] = 'schedule_days';
        }

        if (array_key_exists('schedule_times', $arguments)) {
            $times = [];
            foreach ($this->requireArray($arguments, 'schedule_times') as $time) {
                if (!is_string($time) || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time) !== 1) {
                    throw new LocalizedException(__(
                        'Every entry in "schedule_times" must be a 24-hour time like "06:30"; '
                        . 'got "%1".',
                        is_scalar($time) ? (string) $time : gettype($time)
                    ));
                }
                $times[] = $time;
            }
            sort($times);
            $feed->setData('schedule_times', implode(',', array_unique($times)));
            $changed[] = 'schedule_times';
        }

        return $changed;
    }

    /**
     * @param Feed $feed
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyFieldMap(Feed $feed, array $arguments): array
    {
        if (!array_key_exists('field_map', $arguments)) {
            return [];
        }

        $rows = [];
        foreach ($this->requireArray($arguments, 'field_map') as $row) {
            if (!is_array($row)) {
                throw new LocalizedException(__('Every entry in "field_map" must be an object.'));
            }
            $column = trim((string) ($row['column'] ?? ''));
            if ($column === '') {
                // The admin drops blank rows silently because a trailing empty
                // row is a normal artefact of a grid widget. Nothing here has a
                // grid, so a blank column is a mistake worth naming.
                throw new LocalizedException(
                    __('Every entry in "field_map" needs a non-empty "column".')
                );
            }
            $rows[] = ['column' => $column, 'value' => (string) ($row['value'] ?? '')];
        }

        // null, never '[]'. Runner::buildPlan() reads a non-empty map as "this
        // is a record feed", so the two are different feeds, not two spellings
        // of an empty one.
        $feed->setData('field_map', $rows === [] ? null : json_encode($rows, JSON_UNESCAPED_SLASHES));

        return ['field_map'];
    }

    /**
     * @param Feed $feed
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyValidationRules(Feed $feed, array $arguments): array
    {
        if (!array_key_exists('validation_rules', $arguments)) {
            return [];
        }

        $rules = $this->requireArray($arguments, 'validation_rules');
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new LocalizedException(
                    __('Every entry in "validation_rules" must be an object.')
                );
            }
        }

        $feed->setData(
            'validation_rules',
            $rules === [] ? null : json_encode(array_values($rules), JSON_UNESCAPED_SLASHES)
        );

        return ['validation_rules'];
    }

    /**
     * Refuse a feed that carries both a template and a field map.
     *
     * The module treats such a feed as template-driven and ignores the map
     * entirely — deliberately, and with a comment saying why. Deliberate is not
     * the same as visible: through an API it means writing a field map, getting
     * a clean save, generating, and finding none of those columns in the file,
     * with no error anywhere. So it is refused here instead, with both ways out
     * named.
     *
     * @param Feed $feed
     * @return void
     * @throws LocalizedException
     */
    private function assertOneRenderMode(Feed $feed): void
    {
        if ($feed->getFieldMap() === [] || trim((string) $feed->getData('template')) === '') {
            return;
        }

        throw new LocalizedException(__(
            'This feed would have both a template and a field map, and the module uses only the '
            . 'template in that case — the field map would be ignored with no error. Either pass '
            . '"template" as an empty string to use the field map, or pass "field_map" as [] to '
            . 'stay template-driven.'
        ));
    }

    /**
     * Compile everything that will be rendered, before any of it is stored.
     *
     * Mirrors the admin's own pre-save compile. A template that does not parse
     * would otherwise be stored happily and fail hours later inside cron, where
     * nobody is looking and the message names a feed rather than a mistake.
     *
     * `filename` is compiled only when it contains "{{", because a plain name is
     * not a template and running it through the engine would make ordinary
     * punctuation a syntax error.
     *
     * @param Feed $feed
     * @return void
     * @throws LocalizedException
     */
    private function assertTemplatesCompile(Feed $feed): void
    {
        $template = (string) $feed->getData('template');
        if (trim($template) !== '') {
            $this->compile($template, 'template');
        }

        $filename = (string) $feed->getData('filename');
        if (str_contains($filename, '{{')) {
            $this->compile($filename, 'filename');
        }

        foreach ($feed->getFieldMap() as $row) {
            $value = (string) ($row['value'] ?? '');
            if ($value !== '') {
                $this->compile($value, sprintf('field_map value for column "%s"', $row['column'] ?? ''));
            }
        }
    }

    /**
     * @param string $source
     * @param string $what
     * @return void
     * @throws LocalizedException
     */
    private function compile(string $source, string $what): void
    {
        try {
            $this->templateEngine->compile($source);
        } catch (TemplateSyntaxException $e) {
            throw new LocalizedException(__('The %1 does not compile: %2', $what, $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return array<int|string, mixed>
     * @throws LocalizedException
     */
    private function requireArray(array $arguments, string $key): array
    {
        $value = $arguments[$key];
        if (!is_array($value)) {
            throw new LocalizedException(__('The "%1" argument must be an array.', $key));
        }

        return $value;
    }
}
