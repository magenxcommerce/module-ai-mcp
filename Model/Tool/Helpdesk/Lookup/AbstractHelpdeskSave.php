<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Shared writing for the help desk lookups that are safe to write.
 *
 * {@see AbstractHelpdeskList} used to say writing any of these was not exposed.
 * That has been narrowed rather than reversed: priorities, departments, custom
 * fields and spam patterns are ordinary configuration an operator edits, and
 * are now writable. **Statuses and gateways still are not**, for the reasons
 * recorded on that class and on {@see ListGateways} — a status code is
 * referenced from store configuration and decides what closing a ticket does,
 * and a gateway holds mailbox credentials and decides where the store's mail
 * intake points.
 *
 * Creates when the id is absent and updates when it is present, matching the
 * module's own save controllers. An update loads the row first, so a field that
 * was not passed keeps its value rather than being blanked.
 *
 * The help desk module has no service-contract layer, so these go through its
 * models and resource models — the same compromise the ticket search makes.
 */
abstract class AbstractHelpdeskSave extends AbstractTool
{
    /** Field kinds a subclass can declare without writing its own validation. */
    protected const TYPE_STRING = 'string';
    protected const TYPE_BOOL = 'boolean';
    protected const TYPE_INT = 'integer';

    /**
     * Plural name of these rows, for the result.
     *
     * @return string
     */
    abstract protected function entityName(): string;

    /**
     * Singular name, for messages about one row.
     *
     * @return string
     */
    abstract protected function entityNameSingular(): string;

    /**
     * The argument that names the row, e.g. "priority_id".
     *
     * @return string
     */
    abstract protected function idArgument(): string;

    /**
     * The writable columns, keyed by argument name.
     *
     * Each entry takes `type` (one of the TYPE_ constants), `description`, and
     * optionally `required` to demand it when creating and `enum` to constrain
     * a string. Declaring the shape here rather than writing a schema and a
     * setter per field is what keeps four save tools from being four copies of
     * the same validation.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function fields(): array;

    /**
     * An empty row of this kind.
     *
     * @return object
     */
    abstract protected function newRow(): object;

    /**
     * Load one row, or return null when there is none.
     *
     * @param int $rowId
     * @return object|null
     */
    abstract protected function loadRow(int $rowId): ?object;

    /**
     * @param object $row
     * @return void
     */
    abstract protected function saveRow(object $row): void;

    /**
     * Reduce the saved row to what is worth reporting back.
     *
     * @param object $row
     * @return array<string, mixed>
     */
    abstract protected function projectRow(object $row): array;

    /**
     * A hook for a field that needs more than its declared type checked.
     *
     * @param object $row
     * @param array<string, mixed> $arguments
     * @return void
     * @throws LocalizedException
     */
    protected function validateRow(object $row, array $arguments): void
    {
        // Nothing by default.
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'Create or update one help desk %s. Omit %s to create a new row; pass it to change an '
            . 'existing one, in which case only the fields you pass are changed. Setting is_active '
            . 'false retires a row without touching the tickets already using it, which is safer '
            . 'than deleting it.',
            $this->entityNameSingular(),
            $this->idArgument()
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $properties = [
            $this->idArgument() => [
                'type' => 'integer',
                'description' => 'The row to change. Omit to create a new one.',
            ],
        ];

        foreach ($this->fields() as $name => $spec) {
            $property = ['type' => $spec['type'], 'description' => $spec['description']];
            if (isset($spec['enum'])) {
                $property['enum'] = $spec['enum'];
            }
            $properties[$name] = $property;
        }

        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $rowId = $this->optionalInt($arguments, $this->idArgument());
        $isNew = $rowId === null;

        if ($isNew) {
            $row = $this->newRow();
        } else {
            $row = $this->loadRow($rowId);
            if ($row === null) {
                throw new LocalizedException(__(
                    'No help desk %1 exists with %2 %3.',
                    $this->entityNameSingular(),
                    $this->idArgument(),
                    $rowId
                ));
            }
        }

        $changed = $this->apply($row, $arguments, $isNew);
        if (!$isNew && $changed === []) {
            throw new LocalizedException(__(
                'Nothing to update: pass at least one field besides %1.',
                $this->idArgument()
            ));
        }

        $this->validateRow($row, $arguments);
        $this->saveRow($row);

        return [
            $isNew ? 'created' : 'updated' => true,
            'tool' => $this->getName(),
            'entity' => $this->entityName(),
            'changed_fields' => $changed,
        ] + $this->projectRow($row);
    }

    /**
     * @param object $row
     * @param array<string, mixed> $arguments
     * @param bool $isNew
     * @return string[]
     * @throws LocalizedException
     */
    private function apply(object $row, array $arguments, bool $isNew): array
    {
        $changed = [];

        foreach ($this->fields() as $name => $spec) {
            $required = $isNew && ($spec['required'] ?? false);

            if (!array_key_exists($name, $arguments) || $arguments[$name] === null) {
                if ($required) {
                    throw new LocalizedException(
                        __('The "%1" argument is required when creating a %2.', $name, $this->entityNameSingular())
                    );
                }
                continue;
            }

            $row->setData($name, $this->cast($name, $spec, $arguments[$name]));
            $changed[] = $name;
        }

        return $changed;
    }

    /**
     * @param string $name
     * @param array<string, mixed> $spec
     * @param mixed $value
     * @return mixed
     * @throws LocalizedException
     */
    private function cast(string $name, array $spec, mixed $value): mixed
    {
        return match ($spec['type']) {
            self::TYPE_BOOL => $this->castBool($name, $value),
            self::TYPE_INT => $this->castInt($name, $value),
            default => $this->castString($name, $spec, $value),
        };
    }

    /**
     * Not a `(bool)` cast, for the reason AbstractTool gives: the string
     * "false" is something a model produces, and casting it yields true.
     *
     * @param string $name
     * @param mixed $value
     * @return int
     * @throws LocalizedException
     */
    private function castBool(string $name, mixed $value): int
    {
        if (!is_bool($value)) {
            throw new LocalizedException(
                __('The "%1" argument must be true or false, not a string or a number.', $name)
            );
        }

        // Stored as a flag column by this module.
        return $value ? 1 : 0;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return int
     * @throws LocalizedException
     */
    private function castInt(string $name, mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new LocalizedException(__('The "%1" argument must be a whole number.', $name));
        }

        return (int) $value;
    }

    /**
     * @param string $name
     * @param array<string, mixed> $spec
     * @param mixed $value
     * @return string
     * @throws LocalizedException
     */
    private function castString(string $name, array $spec, mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new LocalizedException(__('The "%1" argument must be a non-empty string.', $name));
        }

        $trimmed = trim($value);
        if (isset($spec['enum']) && !in_array($trimmed, $spec['enum'], true)) {
            throw new LocalizedException(__(
                'The "%1" argument must be one of: %2.',
                $name,
                implode(', ', $spec['enum'])
            ));
        }

        return $trimmed;
    }
}
