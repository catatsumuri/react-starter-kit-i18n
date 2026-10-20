<?php

namespace App\Models;

use App\Actions\LogSecurityEvent;
use LogicException;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * The activity model used by the application.
 *
 * Records of the security log are append-only: they cannot be updated or
 * deleted through the model. The retention cleanup removes expired records with
 * a bulk query, which does not go through model events.
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
        if ($this->log_name === LogSecurityEvent::LOG_NAME) {
            throw new LogicException("Security log records cannot be {$action}.");
        }
    }
}
