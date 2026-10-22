<?php

namespace Modules\Service\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HomeFeedCache
{
    public static function key(int $companyId, int $customerId): string
    {
        $version = Cache::get("announcements:{$companyId}:version", '1');

        // v2: the entry holds attribute rows. A v1 entry (models, written before the cache refused objects) is never read.
        return "announcements:{$companyId}:{$version}:v2:customer:{$customerId}";
    }

    public static function flush(int $companyId): void
    {
        DB::afterCommit(fn () => Cache::forever("announcements:{$companyId}:version", (string) Str::uuid()));
    }
}
