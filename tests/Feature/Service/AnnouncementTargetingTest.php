<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Queries\CustomerAudienceQuery;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class AnnouncementTargetingTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_targeting_matrix_uses_location_subtrees_categories_and_active_or_suspended_subscriptions(): void
    {
        $company = Company::factory()->create(['timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $otherCompany = Company::factory()->create(['timezone' => 'Asia/Jakarta', 'is_active' => true]);
        [$user, $customer] = $this->customerLogin($company);
        [, $suspendedCustomer] = $this->customerLogin($company);
        [, $pendingCustomer] = $this->customerLogin($company);
        [, $otherCustomer] = $this->customerLogin($otherCompany);

        $area = Location::factory()->create(['company_id' => $company->id, 'code' => 'AREA', 'path' => 'AREA']);
        $pop = Location::factory()->create([
            'company_id' => $company->id,
            'parent_id' => $area->id,
            'code' => 'POP-1',
            'path' => 'AREA > POP-1',
            'type' => 'pop',
        ]);
        $otherArea = Location::factory()->create(['company_id' => $company->id, 'code' => 'OTHER', 'path' => 'OTHER']);
        $category = ServicePackageCategory::factory()->create(['company_id' => $company->id]);
        $otherCategory = ServicePackageCategory::factory()->create(['company_id' => $company->id]);

        foreach ([[$customer, 'active'], [$suspendedCustomer, 'suspended'], [$pendingCustomer, 'pending']] as [$subscriber, $status]) {
            $this->subscription($subscriber, $pop, $category, $status);
        }

        $foreignPop = Location::factory()->create(['company_id' => $otherCompany->id, 'code' => 'POP-X', 'path' => 'POP-X', 'type' => 'pop']);
        $foreignCategory = ServicePackageCategory::factory()->create(['company_id' => $otherCompany->id]);
        $this->subscription($otherCustomer, $foreignPop, $foreignCategory, 'active');

        $all = $this->publishedAnnouncement($company, 'Semua', 'all');
        $location = $this->publishedAnnouncement($company, 'Lokasi', 'targeted');
        $location->locations()->sync([$area->id]);
        $categoryOnly = $this->publishedAnnouncement($company, 'Kategori', 'targeted');
        $categoryOnly->servicePackageCategories()->sync([$category->id]);
        $both = $this->publishedAnnouncement($company, 'Keduanya', 'targeted');
        $both->locations()->sync([$area->id]);
        $both->servicePackageCategories()->sync([$category->id]);
        $none = $this->publishedAnnouncement($company, 'Tanpa target', 'targeted');
        $wrongLocation = $this->publishedAnnouncement($company, 'Lokasi salah', 'targeted');
        $wrongLocation->locations()->sync([$otherArea->id]);
        $wrongCategory = $this->publishedAnnouncement($company, 'Kategori salah', 'targeted');
        $wrongCategory->servicePackageCategories()->sync([$otherCategory->id]);

        CompanyService::runAs((int) $company->id, function () use ($customer, $suspendedCustomer, $pendingCustomer): void {
            $query = app(CustomerAudienceQuery::class);

            $this->assertSame(
                ['Semua', 'Lokasi', 'Kategori', 'Keduanya'],
                $query->visibleAnnouncements($customer)->orderBy('id')->pluck('title')->all(),
            );
            $this->assertSame(
                ['Semua', 'Lokasi', 'Kategori', 'Keduanya'],
                $query->visibleAnnouncements($suspendedCustomer)->orderBy('id')->pluck('title')->all(),
            );
            $this->assertSame(['Semua'], $query->visibleAnnouncements($pendingCustomer)->orderBy('id')->pluck('title')->all());
        });

        $token = $this->issueToken($user, 'customer');
        $feed = $this->api('GET', 'customer/home-feed', token: $token)
            ->assertOk()
            ->assertJsonMissing(['title' => 'Tanpa target'])
            ->assertJsonMissing(['title' => 'Lokasi salah'])
            ->assertJsonMissing(['title' => 'Kategori salah']);
        $this->assertEqualsCanonicalizing(
            ['Semua', 'Lokasi', 'Kategori', 'Keduanya'],
            collect($feed->json('data.announcements'))->pluck('title')->all(),
        );

        CompanyService::runAs((int) $otherCompany->id, function () use ($otherCustomer): void {
            $this->assertSame([], app(CustomerAudienceQuery::class)->visibleAnnouncements($otherCustomer)->pluck('title')->all());
        });

        $this->assertModelExists($all);
        $this->assertModelExists($none);
    }

    public function test_status_and_visibility_change_at_company_local_period_boundaries(): void
    {
        $company = Company::factory()->create(['timezone' => 'Asia/Jakarta', 'is_active' => true]);
        [, $customer] = $this->customerLogin($company);
        CarbonImmutable::setTestNow('2026-10-15 00:59:59 UTC');

        try {
            $scheduled = CompanyService::runAs((int) $company->id, fn () => Announcement::query()->create([
                'title' => 'Terjadwal',
                'body' => 'Gangguan singkat.',
                'kind' => 'outage',
                'audience' => 'all',
                'starts_at' => '2026-10-15 01:00:00',
                'ends_at' => '2026-10-15 02:00:00',
            ]));
            $scheduled->forceFill(['published_at' => now(), 'is_active' => true])->save();
            $draft = CompanyService::runAs((int) $company->id, fn () => Announcement::query()->create([
                'title' => 'Draf',
                'body' => 'Belum terbit.',
                'kind' => 'info',
                'audience' => 'all',
                'starts_at' => now()->subHour(),
            ]));

            $this->assertSame('scheduled', $scheduled->status());
            $this->assertSame('draft', $draft->status());
            CompanyService::runAs((int) $company->id, fn () => $this->assertFalse(app(CustomerAudienceQuery::class)->visibleAnnouncements($customer)->whereKey($scheduled)->exists()));

            CarbonImmutable::setTestNow('2026-10-15 01:00:00 UTC');
            $this->assertSame('live', $scheduled->status());
            CompanyService::runAs((int) $company->id, fn () => $this->assertTrue(app(CustomerAudienceQuery::class)->visibleAnnouncements($customer)->whereKey($scheduled)->exists()));

            CarbonImmutable::setTestNow('2026-10-15 02:00:00 UTC');
            $this->assertSame('ended', $scheduled->status());
            CompanyService::runAs((int) $company->id, fn () => $this->assertFalse(app(CustomerAudienceQuery::class)->visibleAnnouncements($customer)->whereKey($scheduled)->exists()));

            $scheduled->forceFill(['is_active' => false])->save();
            $this->assertSame('inactive', $scheduled->status());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function subscription(Customer $customer, Location $pop, ServicePackageCategory $category, string $status): ServiceSubscription
    {
        $package = ServicePackageFactory::new()->create([
            'company_id' => $customer->company_id,
            'service_package_category_id' => $category->id,
        ]);
        $address = CustomerAddress::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
        ]);

        return ServiceSubscription::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'serving_pop_id' => $pop->id,
            'status' => $status,
        ]);
    }

    private function publishedAnnouncement(Company $company, string $title, string $audience): Announcement
    {
        $announcement = CompanyService::runAs((int) $company->id, fn () => Announcement::query()->create([
            'title' => $title,
            'body' => $title,
            'kind' => 'info',
            'audience' => $audience,
            'starts_at' => now()->subMinute(),
        ]));
        $announcement->forceFill(['published_at' => now(), 'is_active' => true])->save();

        return $announcement;
    }
}
