<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\PromoBanner;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class AnnouncementFeedTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEElEQVR42mNk+M/wn4GBgQEAGQID/df43NwAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_feed_caps_orders_and_allow_lists_banners_and_announcements(): void
    {
        [$user, $customer] = $this->customerLogin();
        $companyId = (int) $customer->company_id;

        CompanyService::runAs($companyId, function () use ($companyId): void {
            Announcement::factory()->published()->create(['title' => 'Informasi', 'kind' => 'info']);
            Announcement::factory()->published()->create(['title' => 'Gangguan', 'kind' => 'outage']);
            foreach (range(1, 6) as $position) {
                $banner = PromoBanner::factory()->published()->create(['title' => "Banner {$position}", 'sort_order' => $position]);
                $banner->addMediaFromString(base64_decode(self::PNG))->usingFileName("banner-{$position}.png")
                    ->withCustomProperties(['company_id' => $companyId])->toMediaCollection('promo_banner');
            }
        });

        $response = $this->api('GET', 'customer/home-feed', token: $this->issueToken($user, 'customer'))->assertOk();

        $this->assertSame(['Gangguan', 'Informasi'], collect($response->json('data.announcements'))->pluck('title')->all());
        $this->assertCount(5, $response->json('data.banners'));
        $this->assertSame(
            ['id', 'title', 'alt_text', 'image_url', 'link_kind', 'link_target', 'starts_at', 'ends_at'],
            array_keys($response->json('data.banners.0')),
        );
    }

    public function test_tap_is_deduplicated_per_user_and_day_and_hidden_banner_is_not_found(): void
    {
        [$user, $customer] = $this->customerLogin();
        [$otherUser] = $this->customerLogin($customer->company);
        $banner = CompanyService::runAs((int) $customer->company_id, fn () => PromoBanner::factory()->published()->create());
        $url = "customer/promo-banners/{$banner->id}/tap";

        $this->api('POST', $url, token: $this->issueToken($user, 'customer'))->assertNoContent();
        $this->api('POST', $url, token: $this->issueToken($user, 'customer'))->assertNoContent();
        $this->api('POST', $url, token: $this->issueToken($otherUser, 'customer'))->assertNoContent();
        $this->assertSame(2, $banner->fresh()->tap_count);

        Cache::clear();
        $banner->forceFill(['is_active' => false])->save();
        $this->api('POST', $url, token: $this->issueToken($user, 'customer'))->assertNotFound();
    }

    public function test_detail_never_returns_an_unmatched_announcement(): void
    {
        [$user, $customer] = $this->customerLogin();
        $announcement = CompanyService::runAs((int) $customer->company_id, fn () => Announcement::factory()->published()->targeted()->create());

        $this->api('GET', "customer/announcements/{$announcement->id}", token: $this->issueToken($user, 'customer'))->assertNotFound();

        $announcement->forceFill(['audience' => 'all'])->save();
        $this->api('GET', "customer/announcements/{$announcement->id}", token: $this->issueToken($user, 'customer'))
            ->assertOk()
            ->assertJsonPath('data.id', $announcement->id);
    }
}
