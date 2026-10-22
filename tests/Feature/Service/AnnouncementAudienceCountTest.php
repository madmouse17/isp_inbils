<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Jobs\SendAnnouncementNotifications;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Queries\CustomerAudienceQuery;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;

/**
 * F7-U02: publishing says "untuk {n} pelanggan" before it sends anything, and "Simpan & Terbitkan" saves and publishes
 * in one step. The number is what the notification job reaches, and the count endpoint returns nothing but the number.
 */
class AnnouncementAudienceCountTest extends TestCase
{
    use BuildsNetworkFixtures;
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Location $area;

    private Location $pop;

    private ServicePackageCategory $category;

    /** @var array<string, Customer> */
    private array $customers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->actingAs($this->admin);

        $this->area = Location::factory()->create(['company_id' => $this->company->id, 'code' => 'AREA', 'path' => 'AREA']);
        $this->pop = Location::factory()->create(['company_id' => $this->company->id, 'parent_id' => $this->area->id, 'code' => 'POP-1', 'path' => 'AREA > POP-1', 'type' => 'pop']);
        $this->category = ServicePackageCategory::factory()->create(['company_id' => $this->company->id]);

        // Who has an app account that works, and a subscription that counts, decides who is reached.
        foreach (['active' => 'active', 'suspended' => 'suspended', 'pending' => 'pending'] as $name => $status) {
            $this->customers[$name] = $this->customerLogin($this->company)[1];
            $this->subscribe($this->customers[$name], $status);
        }
        $this->customers['inactive account'] = $this->customerLogin($this->company, ['is_active' => false])[1];
        $this->subscribe($this->customers['inactive account'], 'active');
        $this->customers['no account'] = Customer::factory()->create(['company_id' => $this->company->id, 'user_id' => null]);
        $this->subscribe($this->customers['no account'], 'active');

        $foreign = Company::factory()->create(['is_active' => true]);
        $foreignCustomer = $this->customerLogin($foreign)[1];
        $this->subscribe($foreignCustomer, 'active', Location::factory()->create(['company_id' => $foreign->id, 'path' => 'X']), ServicePackageCategory::factory()->create(['company_id' => $foreign->id]));
    }

    private function subscribe(Customer $customer, string $status, ?Location $pop = null, ?ServicePackageCategory $category = null): void
    {
        $package = ServicePackageFactory::new()->create(['company_id' => $customer->company_id, 'service_package_category_id' => ($category ?? $this->category)->id]);
        $address = CustomerAddress::factory()->create(['company_id' => $customer->company_id, 'customer_id' => $customer->id]);

        ServiceSubscription::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'serving_pop_id' => ($pop ?? $this->pop)->id,
            'status' => $status,
        ]);
    }

    private function recipients(array $targeting): int
    {
        return $this->postJson(route('admin.announcements.audience'), $targeting)->assertOk()->json('count');
    }

    public function test_it_counts_the_customers_with_a_working_app_account_in_the_targeting(): void
    {
        $otherArea = Location::factory()->create(['company_id' => $this->company->id, 'code' => 'OTHER', 'path' => 'OTHER']);
        $otherCategory = ServicePackageCategory::factory()->create(['company_id' => $this->company->id]);

        $this->assertSame(3, $this->recipients(['audience' => 'all']), 'active, suspended and pending customers hold an account; the inactive one, the one without one and another company do not');
        $this->assertSame(2, $this->recipients(['audience' => 'targeted', 'location_ids' => [$this->area->public_id]]), 'the area holds its POP, and a pending subscription is no audience');
        $this->assertSame(2, $this->recipients(['audience' => 'targeted', 'location_ids' => [$this->pop->public_id]]));
        $this->assertSame(2, $this->recipients(['audience' => 'targeted', 'service_package_category_ids' => [$this->category->public_id]]));
        $this->assertSame(2, $this->recipients(['audience' => 'targeted', 'location_ids' => [$this->area->public_id], 'service_package_category_ids' => [$this->category->public_id]]));
        $this->assertSame(0, $this->recipients(['audience' => 'targeted', 'location_ids' => [$otherArea->public_id]]));
        $this->assertSame(0, $this->recipients(['audience' => 'targeted', 'service_package_category_ids' => [$otherCategory->public_id]]));
        $this->assertSame(0, $this->recipients(['audience' => 'targeted', 'location_ids' => [$this->area->public_id], 'service_package_category_ids' => [$otherCategory->public_id]]));
    }

    public function test_the_endpoint_returns_the_count_and_nothing_else(): void
    {
        $this->postJson(route('admin.announcements.audience'), ['audience' => 'all'])->assertOk()->assertExactJson(['count' => 3]);
    }

    public function test_it_refuses_what_saving_would_refuse_and_what_belongs_to_another_company(): void
    {
        $foreignArea = Location::factory()->create(['company_id' => Company::factory()->create(['is_active' => true])->id, 'path' => 'F']);

        $this->postJson(route('admin.announcements.audience'), ['audience' => 'targeted'])->assertUnprocessable()->assertJsonValidationErrors('audience');
        $this->postJson(route('admin.announcements.audience'), ['audience' => 'everyone'])->assertUnprocessable()->assertJsonValidationErrors('audience');
        $this->postJson(route('admin.announcements.audience'), ['audience' => 'targeted', 'location_ids' => [$foreignArea->public_id]])->assertUnprocessable()->assertJsonValidationErrors('location_ids.0');
        $this->postJson(route('admin.announcements.audience'), ['audience' => 'targeted', 'location_ids' => [$this->area->id]])->assertUnprocessable()->assertJsonValidationErrors('location_ids.0');
    }

    public function test_it_needs_the_announcement_permission(): void
    {
        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.nas.view'));

        $this->postJson(route('admin.announcements.audience'), ['audience' => 'all'])->assertForbidden();

        $this->app['auth']->logout();
        $this->postJson(route('admin.announcements.audience'), ['audience' => 'all'])->assertUnauthorized();
    }

    public function test_the_count_is_what_the_notification_job_reaches(): void
    {
        $announcement = CompanyService::runAs((int) $this->company->id, function (): Announcement {
            $announcement = Announcement::factory()->published()->create(['kind' => 'maintenance', 'audience' => 'targeted']);
            $announcement->locations()->sync([$this->area->id]);
            $announcement->servicePackageCategories()->sync([$this->category->id]);

            return $announcement;
        });
        $asked = $this->recipients(['audience' => 'targeted', 'location_ids' => [$this->area->public_id], 'service_package_category_ids' => [$this->category->public_id]]);

        (new SendAnnouncementNotifications($announcement->id))->handle();

        $notified = collect($this->customers)->sum(fn (Customer $customer): int => $customer->notifications()->count());
        $this->assertSame($notified, $asked);
        $this->assertSame($asked, app(CustomerAudienceQuery::class)->recipientCount('targeted', [$this->area->id], [$this->category->id]));
    }

    public function test_the_list_counts_the_drafts_of_the_page_and_no_published_row(): void
    {
        $draft = Announcement::factory()->create(['company_id' => $this->company->id, 'audience' => 'all']);
        $targeted = Announcement::factory()->targeted()->create(['company_id' => $this->company->id]);
        $targeted->locations()->sync([$this->area->id]);
        $published = Announcement::factory()->published()->create(['company_id' => $this->company->id]);

        $this->get(route('admin.announcements.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where("recipientCounts.{$draft->public_id}", 3)
            ->where("recipientCounts.{$targeted->public_id}", 2)
            ->missing("recipientCounts.{$published->public_id}"));
    }

    public function test_save_and_publish_is_one_step_and_a_refused_publish_keeps_nothing(): void
    {
        $payload = [
            'title' => 'Gangguan Dago',
            'body' => 'Perbaikan kabel.',
            'kind' => 'info',
            'audience' => 'all',
            'starts_at' => now()->subHour()->format('Y-m-d H:i'),
        ];

        $this->post(route('admin.announcements.store'), $payload + ['publish' => true])
            ->assertRedirect()
            ->assertSessionHas('success', 'Pengumuman disimpan dan diterbitkan.');
        $published = Announcement::query()->where('title', 'Gangguan Dago')->sole();
        $this->assertNotNull($published->published_at);

        $this->post(route('admin.announcements.store'), ['title' => 'Hanya draf'] + $payload)->assertRedirect()->assertSessionHas('success', 'Pengumuman disimpan sebagai draf.');
        $this->assertNull(Announcement::query()->where('title', 'Hanya draf')->sole()->published_at);

        // The period is over: publishing is refused, so the new row is not kept either and the form can be sent again.
        $over = ['title' => 'Sudah lewat', 'starts_at' => now()->subDays(2)->format('Y-m-d H:i'), 'ends_at' => now()->subDay()->format('Y-m-d H:i')] + $payload;
        $this->postJson(route('admin.announcements.store'), $over + ['publish' => true])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->assertSame(0, Announcement::query()->where('title', 'Sudah lewat')->count());

        // A published row cannot be published again, and the change that came with the request is not applied.
        $this->putJson(route('admin.announcements.update', $published), ['title' => 'Judul baru'] + $payload + ['publish' => true])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('Gangguan Dago', $published->fresh()->title);
    }
}
