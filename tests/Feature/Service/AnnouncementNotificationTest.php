<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Modules\Service\Console\CleanupPromoBannerImages;
use Modules\Service\Console\PublishScheduledAnnouncements;
use Modules\Service\Jobs\SendAnnouncementNotifications;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Services\AnnouncementService;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class AnnouncementNotificationTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEElEQVR42mNk+M/wn4GBgQEAGQID/df43NwAAAAASUVORK5CYII=';

    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_publish_dispatches_once_for_outage_or_maintenance_but_not_info_or_promo(): void
    {
        Bus::fake();
        $company = Company::factory()->create(['is_active' => true]);

        CompanyService::runAs((int) $company->id, function () use ($company): void {
            $outage = Announcement::factory()->create(['company_id' => $company->id, 'kind' => 'outage']);
            app(AnnouncementService::class)->publish($outage);
            Bus::assertDispatched(SendAnnouncementNotifications::class, fn (SendAnnouncementNotifications $job) => $job->announcementId === $outage->id);

            $info = Announcement::factory()->create(['company_id' => $company->id, 'kind' => 'info']);
            app(AnnouncementService::class)->publish($info);
            Bus::assertDispatchedTimes(SendAnnouncementNotifications::class, 1);

            PromoBanner::factory()->published()->create(['company_id' => $company->id]);
            Bus::assertDispatchedTimes(SendAnnouncementNotifications::class, 1);
        });
    }

    public function test_fan_out_sends_one_database_notification_per_targeted_customer_user(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        [, $customer] = $this->customerLogin($company);
        [, $second] = $this->customerLogin($company);
        $announcement = CompanyService::runAs((int) $company->id, fn () => Announcement::factory()->published()->create(['kind' => 'maintenance']));

        $job = new SendAnnouncementNotifications($announcement->id);
        $job->handle();
        $job->handle();

        $this->assertSame(1, $customer->notifications()->count());
        $this->assertSame(1, $second->notifications()->count());
        $this->assertEqualsCanonicalizing(['type' => 'announcement', 'id' => $announcement->id], $customer->notifications()->firstOrFail()->data['subject']);
        $this->assertNotNull($announcement->fresh()->notified_at);
    }

    public function test_scheduled_command_dispatches_due_notifications_and_cleanup_removes_only_old_images(): void
    {
        Bus::fake();
        Storage::fake('local');
        $company = Company::factory()->create(['is_active' => true]);
        [$due, $future, $oldBanner, $recentBanner] = CompanyService::runAs((int) $company->id, function (): array {
            $due = Announcement::factory()->published()->create(['kind' => 'outage', 'starts_at' => now()->subMinute()]);
            $future = Announcement::factory()->published()->create(['kind' => 'outage', 'starts_at' => now()->addHour()]);
            $oldBanner = PromoBanner::factory()->published()->create(['ends_at' => now()->subDays(181)]);
            $recentBanner = PromoBanner::factory()->published()->create(['ends_at' => now()->subDays(179)]);
            foreach ([$oldBanner, $recentBanner] as $banner) {
                $banner->addMediaFromString(base64_decode(self::PNG))->usingFileName('banner.png')
                    ->withCustomProperties(['company_id' => $banner->company_id])->toMediaCollection('promo_banner');
            }

            return [$due, $future, $oldBanner, $recentBanner];
        });

        app(PublishScheduledAnnouncements::class)->handle();
        Bus::assertDispatched(SendAnnouncementNotifications::class, fn (SendAnnouncementNotifications $job) => $job->announcementId === $due->id);
        Bus::assertNotDispatched(SendAnnouncementNotifications::class, fn (SendAnnouncementNotifications $job) => $job->announcementId === $future->id);

        app(CleanupPromoBannerImages::class)->handle();
        $this->assertFalse($oldBanner->fresh()->hasMedia('promo_banner'));
        $this->assertTrue($recentBanner->fresh()->hasMedia('promo_banner'));
    }
}
