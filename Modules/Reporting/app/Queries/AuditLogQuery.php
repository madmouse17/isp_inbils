<?php

namespace Modules\Reporting\Queries;

use Spatie\Activitylog\Models\Activity;

class AuditLogQuery
{
    public static function execute(?int $userId = null, ?string $logName = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = Activity::query();
        if ($userId) $query->where('causer_id', $userId)->where('causer_type', 'App\\Models\\User');
        if ($logName) $query->where('log_name', $logName);
        if ($dateFrom && $dateTo) $query->whereBetween('created_at', [$dateFrom, $dateTo]);

        return $query->latest()->limit(500)->get()->map(fn ($a) => [
            'id' => $a->id,
            'log_name' => $a->log_name,
            'description' => $a->description,
            'causer_id' => $a->causer_id,
            'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id,
            'properties' => $a->properties->toArray(),
            'created_at' => $a->created_at,
        ])->toArray();
    }
}
