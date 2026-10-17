<?php

namespace Modules\Service\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class HomeFeedCache
{
    public static function key(int $companyId, int $customerId): string
    {
        $version = Cache::get("announcements:{$companyId}:version", '1');

        return "announcements:{$companyId}:{$version}:customer:{$customerId}";
    }

    public static function flush(int $companyId): void
    {
        Cache::forever("announcements:{$companyId}:version", (string) Str::uuid());
    }
}
