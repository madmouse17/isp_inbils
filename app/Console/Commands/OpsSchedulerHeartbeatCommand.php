<?php

namespace App\Console\Commands;

use App\Support\Observability\ReadinessChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class OpsSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'ops:scheduler-heartbeat';

    protected $description = 'Write scheduler heartbeat cache key for /ready probe';

    public function handle(): int
    {
        Cache::put(
            ReadinessChecker::SCHEDULER_HEARTBEAT_KEY,
            now()->toIso8601String(),
            600
        );

        return self::SUCCESS;
    }
}
