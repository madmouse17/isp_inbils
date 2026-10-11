<?php

namespace App\Console\Commands;

use App\Support\Observability\ReadinessChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class OpsBackupHeartbeatCommand extends Command
{
    protected $signature = 'ops:backup-heartbeat';

    protected $description = 'Write backup freshness cache key for /ready probe (run after a verified backup)';

    /**
     * Cache TTL in seconds — 25 hours covers 24h RPO + grace period.
     */
    private const BACKUP_TTL = 90000;

    public function handle(): int
    {
        Cache::put(
            ReadinessChecker::BACKUP_HEARTBEAT_KEY,
            now()->toIso8601String(),
            self::BACKUP_TTL
        );

        return self::SUCCESS;
    }
}
