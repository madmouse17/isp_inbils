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

    public const BACKUP_HEARTBEAT_KEY = 'ops:backup:heartbeat_at';

    /** Heartbeat older than this → degraded (seconds). */
    private const HEARTBEAT_MAX_AGE = 300;

    /**
     * Backup marker age limit (seconds) = 24h RPO + grace, provisional until owner approval.
     */
    private const BACKUP_HEARTBEAT_MAX_AGE = 90000;

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
            'storage_link' => $this->storageLink(),
            'mail' => $this->mail(),
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
        try {
            $at = Cache::get(self::BACKUP_HEARTBEAT_KEY);
            if (! is_string($at) || $at === '') {
                return 'unknown';
            }

            $ts = strtotime($at);
            if ($ts === false) {
                return 'unknown';
            }

            $age = time() - $ts;

            return $age <= self::BACKUP_HEARTBEAT_MAX_AGE ? 'ok' : 'fail';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * K22: a production sender left on a placeholder domain (the example.env default) means every reset link and invoice
     * mail leaves from an address nobody owns. Only production is judged: elsewhere the sender is a stand-in by design.
     */
    private function mail(): string
    {
        if (! app()->environment('production')) {
            return 'unknown';
        }

        $address = strtolower(trim((string) config('mail.from.address')));

        return $address === '' || str_ends_with($address, 'example.com') || str_ends_with($address, '.local') ? 'fail' : 'ok';
    }

    /**
     * F1-D01: an uploaded logo showed as a broken image because `public/storage` was a real directory (a copy made once),
     * so /storage/... never reached the public disk. Ok when every link config/filesystems.php declares is a link to its
     * target. PHPUnit never serves a file, so a test run is not asked: the answer would depend on the checkout.
     */
    private function storageLink(): string
    {
        $links = config('filesystems.links', []);

        if (app()->environment('testing') || ! is_array($links) || $links === []) {
            return 'unknown';
        }

        foreach ($links as $link => $target) {
            $resolved = is_link($link) ? realpath($link) : false;

            if ($resolved === false || $resolved !== realpath($target)) {
                return 'fail';
            }
        }

        return 'ok';
    }
}
