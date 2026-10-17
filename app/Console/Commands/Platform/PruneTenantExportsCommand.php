<?php

namespace App\Console\Commands\Platform;

use App\Services\Platform\TenantExportService;
use Illuminate\Console\Command;

class PruneTenantExportsCommand extends Command
{
    protected $signature = 'inbils:platform:exports:prune';

    protected $description = 'Expire old tenant export archives and fail stale builds.';

    public function handle(TenantExportService $exports): int
    {
        $counts = $exports->prune();
        $this->info("Expired {$counts['expired']} export archive(s), failed {$counts['failed']} stale build(s).");

        return self::SUCCESS;
    }
}
