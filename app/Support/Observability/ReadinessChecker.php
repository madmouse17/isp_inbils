<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Public-safe readiness probes. Values: ok|fail|unknown only.
 * Never leak exception messages, hosts, credentials.
 */
final class ReadinessChecker
{
    public const SCHEDULER_HEARTBEAT_KEY = 'ops:scheduler:heartbeat_at';

    /** Heartbeat older than this → degraded (seconds). */
    private const HEARTBEAT_MAX_AGE = 300;

    /**
     * @return array{status: string, checks: array<string, string>}
     */
    public function check(): array
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'queue' => $this->queue(),
            'failed_jobs' => $this->failedJobs(),
            'scheduler' => $this->scheduler(),
            'backup' => $this->backup(),
        ];

        $status = $this->aggregate($checks);

        return ['status' => $status, 'checks' => $checks];
    }

    /**
     * @param  array<string, string>  $checks
     */
    private function aggregate(array $checks): string
    {
        if ($checks['database'] === 'fail') {
            return 'not_ready';
        }

        foreach ($checks as $value) {
            if ($value === 'fail') {
                return 'degraded';
            }
        }

        return 'ready';
    }

    private function database(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }

    private function cache(): string
    {
        try {
            $key = 'ops:ready:probe:'.uniqid('', true);
            Cache::put($key, '1', 10);
            $ok = Cache::get($key) === '1';
            Cache::forget($key);

            return $ok ? 'ok' : 'fail';
        } catch (Throwable) {
            return 'fail';
        }
    }

    private function queue(): string
    {
        // ponytail: only database driver exposes jobs table; others unknown
        if (config('queue.default') !== 'database') {
            return 'unknown';
        }

        try {
            if (! Schema::hasTable('jobs')) {
                return 'unknown';
            }

            // Presence probe only — depth is ops-side metric; fail if table unreadable
            DB::table('jobs')->limit(1)->count();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }

    private function failedJobs(): string
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return 'unknown';
            }

            DB::table('failed_jobs')->limit(1)->count();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }

    private function scheduler(): string
    {
        try {
            $at = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
            if (! is_string($at) || $at === '') {
                return 'unknown';
            }

            $ts = strtotime($at);
            if ($ts === false) {
                return 'unknown';
            }

            return (time() - $ts) <= self::HEARTBEAT_MAX_AGE ? 'ok' : 'fail';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function backup(): string
    {
        // ponytail: no app-owned backup marker path yet → unknown, never fake green
        return 'unknown';
    }
}
