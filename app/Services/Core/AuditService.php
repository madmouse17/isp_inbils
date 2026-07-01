<?php

namespace App\Services\Core;

use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public static function log(string $module, string $action, array $properties = [], ?Model $subject = null): void
    {
        $activity = activity($module)->withProperties($properties);

        if ($subject) {
            $activity->performedOn($subject);
        }

        $activity->log($action);
    }

    public static function logModelActivity(Model $model, string $event): void
    {
        activity(strtolower(class_basename($model)))
            ->performedOn($model)
            ->withProperties($model->getAttributes())
            ->log($event);
    }
}
