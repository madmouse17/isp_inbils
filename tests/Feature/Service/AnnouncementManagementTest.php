<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Models\ServicePackageCategory;
use Tests\TestCase;

class AnnouncementManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->company = Company::factory()->create(['timezone' => 'Asia/Jakarta', 'is_active' => true]);
    }

    public function test_manager_can_manage_and_staff_is_forbidden(): void
    {
        $manager = $this->user('manager');
        $staff = $this->user('staff');

        $this->actingAs($manager)->get(route('admin.announcements.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.announcements.index'))->assertForbidden();

        foreach (['admin' => true, 'manager' => true, 'staff' => false, 'technician' => false, 'customer' => false] as $role => $allowed) {
            $this->assertSame($allowed, $this->user($role)->can('announcement.manage'), $role);
        }
    }

    public function test_announcement_payload_converts_company_local_time_and_preserves_targets(): void
    {
        $manager = $this->user('manager');
        $location = Location::factory()->create(['company_id' => $this->company->id]);
        $category = ServicePackageCategory::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($manager)->post(route('admin.announcements.store'), [
            'title' => 'Pemeliharaan jaringan',
            'body' => "Layanan berhenti sementara.\nMohon menunggu.",
            'kind' => 'maintenance',
            'audience' => 'targeted',
            'location_ids' => [$location->public_id],
            'service_package_category_ids' => [$category->public_id],
            'starts_at' => '2026-10-15 08:00',
            'ends_at' => '2026-10-15 09:30',
        ])->assertRedirect();

        $announcement = Announcement::query()->firstOrFail();
        $this->assertSame('2026-10-15 01:00:00', $announcement->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame([$location->id], $announcement->locations()->pluck('locations.id')->all());
        $this->assertSame([$category->id], $announcement->servicePackageCategories()->pluck('service_package_categories.id')->all());
    }

    public function test_the_index_offers_the_active_package_categories_by_public_id(): void
    {
        $active = ServicePackageCategory::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        ServicePackageCategory::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);

        $this->actingAs($this->user('manager'))->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('packageCategories', fn ($rows) => collect($rows)->pluck('id')->all() === [$active->public_id]));
    }

    public function test_banner_validates_type_size_ratio_alt_link_and_stores_private_media(): void
    {
        $manager = $this->user('manager');
        $base = [
            'title' => 'Promo Oktober', 'alt_text' => 'Potongan biaya pemasangan bulan Oktober',
            'link_kind' => 'app', 'link_target' => 'invoices', 'audience' => 'all',
            'starts_at' => '2026-10-15 08:00', 'sort_order' => 1,
        ];

        $this->actingAs($manager)->post(route('admin.promo-banners.store'), $base + [
            'image' => UploadedFile::fake()->image('bad.png', 1000, 600),
        ])->assertSessionHasErrors('image');
        $this->actingAs($manager)->post(route('admin.promo-banners.store'), array_diff_key($base, ['alt_text' => true]) + [
            'image' => UploadedFile::fake()->image('banner.png', 1200, 600),
        ])->assertSessionHasErrors('alt_text');
        $this->actingAs($manager)->post(route('admin.promo-banners.store'), $base + [
            'image' => UploadedFile::fake()->image('banner.png', 1200, 600)->size(2100),
        ])->assertSessionHasErrors('image');
        $this->actingAs($manager)->post(route('admin.promo-banners.store'), $base + [
            'image' => UploadedFile::fake()->createWithContent('banner.svg', '<svg/>'),
        ])->assertSessionHasErrors('image');

        $this->actingAs($manager)->post(route('admin.promo-banners.store'), $base + [
            'image' => UploadedFile::fake()->image('banner.webp', 1200, 600),
        ])->assertRedirect();

        $banner = PromoBanner::query()->firstOrFail();
        $media = $banner->getFirstMedia('promo_banner');
        $this->assertNotNull($media);
        $this->assertSame('local', $media->disk);
        Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_status_filter_returns_each_lifecycle_status_on_both_tabs(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 03:00:00 UTC');

        try {
            $manager = $this->user('manager');
            // Boundaries on purpose: starting exactly now is live, ending exactly now is ended, and deactivating wins over a future start.
            $fixtures = [
                ['draft', ['published_at' => null]],
                ['scheduled', ['starts_at' => now()->addHour()]],
                ['live', ['starts_at' => now()->subHour()]],
                ['live', ['starts_at' => now()]],
                ['live', ['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]],
                ['ended', ['starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]],
                ['ended', ['starts_at' => now()->subHours(2), 'ends_at' => now()]],
                ['inactive', ['starts_at' => now()->subHour(), 'is_active' => false]],
                ['inactive', ['starts_at' => now()->addHour(), 'is_active' => false]],
            ];

            foreach (['announcements' => Announcement::class, 'banners' => PromoBanner::class] as $tab => $model) {
                $idsByStatus = [];
                foreach ($fixtures as [$status, $attributes]) {
                    $idsByStatus[$status][] = $model::factory()->published()->create(['company_id' => $this->company->id, ...$attributes])->public_id;
                }
                $everyId = array_merge(...array_values($idsByStatus));

                foreach ($idsByStatus as $status => $ids) {
                    $this->assertListedIds($manager, $tab, ['status' => $status], $ids);
                }
                $this->assertListedIds($manager, $tab, [], $everyId);
                $this->assertListedIds($manager, $tab, ['status' => 'archived'], $everyId);
            }

            $this->actingAs($manager)->get(route('admin.announcements.index', ['status' => 'live']))
                ->assertInertia(fn (Assert $page) => $page->where('filters.status', 'live'));
            $this->actingAs($manager)->get(route('admin.announcements.index', ['status' => 'archived']))
                ->assertInertia(fn (Assert $page) => $page->missing('filters.status'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * @param  array<string, string>  $query
     * @param  list<string>  $ids
     */
    private function assertListedIds(User $user, string $tab, array $query, array $ids): void
    {
        $expected = collect($ids)->sort()->values()->all();

        $this->actingAs($user)->get(route('admin.announcements.index', ['tab' => $tab, ...$query]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Announcements/Index', false)
                ->where("{$tab}.data", fn ($rows) => $rows->pluck('id')->sort()->values()->all() === $expected));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($user, $role);

        return $user;
    }
}
