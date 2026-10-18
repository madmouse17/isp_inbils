<?php

namespace Tests\Feature\Integrity;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Service\Services\HomeFeedCache;
use RuntimeException;
use Tests\TestCase;

class HomeFeedCacheCommitTest extends TestCase
{
    use DatabaseTransactions;

    private const COMPANY_ID = 987654;

    public function test_the_feed_version_changes_only_after_commit_and_not_on_rollback(): void
    {
        $version = HomeFeedCache::key(self::COMPANY_ID, 1);

        try {
            DB::transaction(function () use ($version): void {
                HomeFeedCache::flush(self::COMPANY_ID);

                $this->assertSame($version, HomeFeedCache::key(self::COMPANY_ID, 1), 'The version changed inside the transaction.');
            });

            $committed = HomeFeedCache::key(self::COMPANY_ID, 1);
            $this->assertNotSame($version, $committed, 'The version did not change after the commit.');

            try {
                DB::transaction(function (): void {
                    HomeFeedCache::flush(self::COMPANY_ID);

                    throw new RuntimeException('rolled back');
                });
            } catch (RuntimeException) {
                // expected
            }

            $this->assertSame($committed, HomeFeedCache::key(self::COMPANY_ID, 1), 'A rolled-back flush changed the version.');

            HomeFeedCache::flush(self::COMPANY_ID);
            $this->assertNotSame($committed, HomeFeedCache::key(self::COMPANY_ID, 1), 'Outside a transaction the flush must run at once.');
        } finally {
            Cache::forget('announcements:'.self::COMPANY_ID.':version');
        }
    }
}
