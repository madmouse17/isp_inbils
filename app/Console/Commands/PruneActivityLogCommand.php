<?php

namespace App\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

class PruneActivityLogCommand extends Command
{
    /** Rows per DELETE: one statement over a year of rows would hold its locks, and the replica, for minutes. */
    private const CHUNK = 5000;

    protected $signature = 'inbils:activity-log:prune';

    protected $description = 'Delete activity log rows past their retention (config/activitylog.php): 365 days, 730 for the security, platform and support logs.';

    public function handle(): int
    {
        /** @var array<string, int> $longLived */
        $longLived = config('activitylog.retention_days_by_log');
        $total = $this->prune('*', (int) config('activitylog.delete_records_older_than_days'), fn (Builder $rows) => $rows
            // NULL NOT IN (...) is NULL, not true: rows without a log name have to be asked for.
            ->whereNull('log_name')
            ->orWhereNotIn('log_name', array_keys($longLived)));

        foreach ($longLived as $log => $days) {
            $total += $this->prune($log, (int) $days, fn (Builder $rows) => $rows->where('log_name', $log));
        }

        $this->info("Pruned {$total} activity log row(s).");

        return self::SUCCESS;
    }

    /** @param  Closure(Builder): mixed  $group  names the rows of one retention group; the cut-off is added here */
    private function prune(string $label, int $days, Closure $group): int
    {
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $chunk = Activity::query()
                ->where($group)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->delete();
            $deleted += $chunk;
        } while ($chunk === self::CHUNK);

        Log::info('activity_log.pruned', ['log' => $label, 'days' => $days, 'deleted' => $deleted]);

        return $deleted;
    }
}
