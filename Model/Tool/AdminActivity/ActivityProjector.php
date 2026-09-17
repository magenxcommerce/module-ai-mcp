<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\AdminActivity;

use Magenx\AdminActivity\Model\Activity;
use Magenx\AdminActivity\Model\ActivityDetail;

/**
 * One admin action, reduced to what is worth sending to a model.
 *
 * The summary deliberately omits `user_agent` and `request_url`: both are long,
 * both are near-identical across a run of actions, and neither answers the
 * question a search is asked. `toDetail()` adds them back, along with the
 * field-level before/after values, because by then the caller has chosen one
 * row and wants everything about it.
 */
class ActivityProjector
{
    /** Longest before/after value returned verbatim. */
    private const MAX_VALUE_LENGTH = 2048;

    /**
     * @param Activity $activity
     * @return array<string, mixed>
     */
    public function toSummary(Activity $activity): array
    {
        return [
            'activity_id' => (int) $activity->getData('activity_id'),
            'created_at' => $activity->getData('created_at'),
            // Stored denormalised so the row stays readable after the admin
            // user is deleted, which is exactly when an audit trail is needed.
            'username' => $activity->getData('username'),
            'user_id' => $this->nullableInt($activity->getData('user_id')),
            'action_type' => $activity->getData('action_type'),
            'status' => $activity->getData('status'),
            'entity_label' => $activity->getData('entity_label'),
            'entity_id' => $activity->getData('entity_id'),
            'entity_name' => $activity->getData('entity_name'),
            'ip_address' => $activity->getData('ip_address'),
        ];
    }

    /**
     * @param Activity $activity
     * @param ActivityDetail[] $details
     * @return array<string, mixed>
     */
    public function toDetail(Activity $activity, array $details): array
    {
        return $this->toSummary($activity) + [
            'entity_type' => $activity->getData('entity_type'),
            'full_action_name' => $activity->getData('full_action_name'),
            'request_url' => $activity->getData('request_url'),
            'user_agent' => $activity->getData('user_agent'),
            'message' => $activity->getData('message'),
            'changes' => array_map(fn (ActivityDetail $detail): array => $this->change($detail), $details),
        ];
    }

    /**
     * One changed field.
     *
     * Values are truncated because the column holds up to 128 KB and a single
     * serialised layout update would otherwise fill the whole reply. The
     * truncation is announced rather than silent: a clipped value that reads as
     * complete is worse than no value, since the whole point of this tool is
     * establishing what a field actually changed from.
     *
     * @param ActivityDetail $detail
     * @return array<string, mixed>
     */
    private function change(ActivityDetail $detail): array
    {
        return [
            'field_name' => $detail->getData('field_name'),
            'old_value' => $this->clip($detail->getData('old_value')),
            'new_value' => $this->clip($detail->getData('new_value')),
        ];
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function clip(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;
        if (strlen($text) <= self::MAX_VALUE_LENGTH) {
            return $text;
        }

        return substr($text, 0, self::MAX_VALUE_LENGTH)
            . sprintf('... (truncated, %d characters total)', strlen($text));
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
