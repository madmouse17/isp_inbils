<?php

namespace Modules\SPK\Support;

use Illuminate\Support\Carbon;
use Modules\SPK\Models\WorkOrder;

/**
 * The four steps of a visit, the same on the web page and in both apps (spec F2). One place decides what each step
 * means, so no client reads statuses and timestamps itself.
 *
 * `current` is the last step the visit has reached; every step up to and including it is reached, and only those carry
 * a time. A step the technician skipped through the app (never pressed "Berangkat") is reached with a null time.
 * Work sent back goes to "in_progress" and the finish loses its time until it is delivered again. A cancelled visit has
 * no steps. A draft or generated SPK has the four steps and has reached none.
 *
 * Needs `assignments` loaded: the first step is the time of the assignment that is still active.
 */
final class TripSteps
{
    private const LABELS = [
        'assigned' => 'Ditugaskan',
        'en_route' => 'Dalam perjalanan',
        'in_progress' => 'Sedang dikerjakan',
        'installed' => 'Selesai dipasang',
    ];

    /** @return list<array{key: string, label: string, at: ?Carbon}> */
    public static function of(WorkOrder $wo): array
    {
        if ($wo->status === 'cancelled') {
            return [];
        }

        $keys = array_keys(self::LABELS);
        $reached = array_search(self::current($wo), $keys, true);
        $times = [
            'assigned' => $wo->assignments->whereNull('unassigned_at')->max('assigned_at'),
            'en_route' => $wo->en_route_at,
            'in_progress' => $wo->started_at,
            'installed' => $wo->submitted_at,
        ];

        $steps = [];
        foreach ($keys as $index => $key) {
            $steps[] = ['key' => $key, 'label' => self::LABELS[$key], 'at' => $reached !== false && $index <= $reached ? $times[$key] : null];
        }

        return $steps;
    }

    /** @return 'assigned'|'en_route'|'in_progress'|'installed'|null */
    public static function current(WorkOrder $wo): ?string
    {
        return match ($wo->status) {
            'assigned' => $wo->en_route_at === null ? 'assigned' : 'en_route',
            'in_progress', 'rejected' => 'in_progress',
            'waiting_review', 'completed' => 'installed',
            default => null,
        };
    }
}
