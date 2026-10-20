<?php

namespace App\Models;

use App\Actions\LogSecurityEvent;
use LogicException;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * The activity model used by the application.
 *
 * Records of the security log are append-only: they cannot be updated or
 * deleted through this model. A record counts as a security record when the
 * stored or the pending log name is "security", so renaming the log in memory
 * does not lift the guard.
 *
 * The guard relies on model events. Operations that skip them are not covered:
 * bulk query updates and deletes (the retention cleanup uses one on purpose),
 * the base spatie Activity model, raw SQL and direct database access.
 */
class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        static::updating(fn (self $activity) => $activity->guardSecurityRecord('updated'));
        static::deleting(fn (self $activity) => $activity->guardSecurityRecord('deleted'));
    }

    protected function guardSecurityRecord(string $action): void
    {
        if ($this->log_name === LogSecurityEvent::LOG_NAME
            || $this->getRawOriginal('log_name') === LogSecurityEvent::LOG_NAME) {
            throw new LogicException("Security log records cannot be {$action}.");
        }
    }
}
