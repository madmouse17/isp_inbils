<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\OpenStreetMapGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Modules\SPK\Notifications\WorkOrderScheduledNotification;
use Tests\TestCase;
use Tests\Traits\AllowsManualCodes;
use Tests\Traits\InteractsWithMobileApi;

/**
 * "Tugaskan sekarang" (K16, F4-U04): the registration form can name the technician and the visit day of the installation
 * SPK it creates, in the same transaction. The people it offers are the Penugasan queue's, the success message tells what
 * will happen (the mail is queued), and the geocoder gives the street line of the address form.
 */
class CustomerOnboardingAssignTest extends TestCase
{
    use AllowsManualCodes;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $technician;

    private ServicePackage $package;

    private Location $pop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Notification::fake();
        $this->seedRegion();
        $this->company = Company::factory()->create(['is_active' => true]);
        $this->setManualCodes($this->company->id, true, 'customer');
        $this->admin = $this->staff(['name' => 'Admin Pendaftaran']);
        $this->grantRole($this->admin, 'admin', $this->company);
        $this->technician = $this->technicianLogin($this->company, ['name' => 'Budi Teknisi']);
        $this->package = ServicePackageFactory::new()->create(['company_id' => $this->company->id]);
        $this->pop = Location::query()->create([
            'company_id' => $this->company->id,
            'code' => 'POP-ASSIGN',
            'name' => 'POP Penugasan',
            'type' => 'pop',
            'path' => 'POP Penugasan',
            'is_active' => true,
        ]);
    }

    public function test_the_registration_names_the_technician_and_the_visit_day_of_the_installation_spk(): void
    {
        $visit = $this->companyDay(3);

        $this->register(['assign_technician_id' => $this->technician->public_id], ['scheduled_date' => $visit])
            ->assertRedirect()
            ->assertSessionHas('success', 'Pelanggan, langganan, dan SPK pemasangan dibuat dan ditugaskan ke Budi Teknisi. Tautan akses akan dikirim ke customer.assign@example.test.');

        $spk = WorkOrder::query()->where('type', 'installation')->sole();
        $this->assertSame(['assigned', $this->technician->id, $visit], [$spk->status, $spk->assigned_to, $spk->scheduled_date->toDateString()]);
        $this->assertSame(1, WorkOrderAssignment::query()->where('work_order_id', $spk->id)->whereNull('unassigned_at')->count());
        Notification::assertSentTo($this->technician, WorkOrderScheduledNotification::class);
    }

    public function test_a_day_without_a_technician_leaves_the_spk_in_the_queue_with_its_day(): void
    {
        $visit = $this->companyDay(6);

        $this->register([], ['scheduled_date' => $visit])->assertRedirect();

        $spk = WorkOrder::query()->where('type', 'installation')->sole();
        $this->assertSame(['generated', null, $visit], [$spk->status, $spk->assigned_to, $spk->scheduled_date->toDateString()]);
        Notification::assertNotSentTo($this->technician, WorkOrderScheduledNotification::class);
    }

    public function test_a_plain_registration_is_what_it_was(): void
    {
        $this->register()
            ->assertRedirect()
            ->assertSessionHas('success', 'Pelanggan, langganan, dan SPK pemasangan dibuat. Tautan akses akan dikirim ke customer.assign@example.test.');

        $spk = WorkOrder::query()->where('type', 'installation')->sole();
        $this->assertSame(['generated', null, null], [$spk->status, $spk->assigned_to, $spk->scheduled_date]);
    }

    public function test_an_inactive_account_gets_no_promise_of_a_mail(): void
    {
        $this->register(['is_active' => false])
            ->assertRedirect()
            ->assertSessionHas('success', 'Pelanggan, langganan, dan SPK pemasangan dibuat.');
    }

    public function test_a_past_day_refuses_the_whole_registration(): void
    {
        $before = $this->footprint();

        $this->register(['assign_technician_id' => $this->technician->public_id], ['scheduled_date' => $this->companyDay(-1)], json: true)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subscription.scheduled_date' => 'Tanggal kunjungan tidak boleh sebelum hari ini.']);

        $this->assertSame($before, $this->footprint());
    }

    public function test_only_someone_who_may_assign_can_name_a_technician(): void
    {
        $clerk = $this->staff(['name' => 'Petugas Pendaftaran']);
        $this->grantPermissions($clerk, ['app.web', 'customer.create', 'customer.view', 'customer.address.manage', 'customer.subscription.manage', 'spk.create'], $this->company);
        $before = $this->footprint();

        $this->actingAs($clerk);
        $this->register(['assign_technician_id' => $this->technician->public_id], json: true, as: $clerk)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assign_technician_id' => 'Anda tidak punya izin menugaskan teknisi.']);
        $this->assertSame($before, $this->footprint(), 'a forged key leaves nothing behind');

        // The same person registers a customer without it, and may still give the day.
        $this->register([], ['scheduled_date' => $this->companyDay(2)], as: $clerk)->assertRedirect();
        $this->assertSame('generated', WorkOrder::query()->where('type', 'installation')->sole()->status);

        $page = $this->get(route('admin.customers.create'))->assertOk()->viewData('page')['props'];
        $this->assertFalse($page['can']['assignTechnician']);
        $this->assertSame([], $page['technicians']);
    }

    public function test_the_technician_must_be_an_active_technician_of_the_company(): void
    {
        $colleague = $this->staff(['name' => 'Bukan Teknisi']);
        $this->grantRole($colleague, 'staff', $this->company);
        $resigned = $this->technicianLogin($this->company, ['name' => 'Sudah Keluar', 'is_active' => false]);
        $foreign = $this->technicianLogin($this->apiCompany(), ['name' => 'Teknisi Lain']);
        $before = $this->footprint();

        foreach ([$colleague, $resigned, $foreign] as $person) {
            $this->register(['assign_technician_id' => $person->public_id], json: true)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('assign_technician_id');
        }

        $this->assertSame($before, $this->footprint());
    }

    public function test_the_company_policy_against_self_assignment_is_a_message_on_the_field(): void
    {
        $this->company->forceFill(['settings' => [...($this->company->settings ?? []), 'forbid_self_assignment' => true]])->save();
        CompanyService::resetCache();
        CompanyService::useCompanyForTests($this->company->id);
        $this->grantRole($this->admin, 'technician', $this->company);
        EmployeeProfile::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->admin->id, 'status' => 'active']);
        $before = $this->footprint();

        $this->register(['assign_technician_id' => $this->admin->public_id], json: true)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assign_technician_id' => 'Kebijakan perusahaan melarang menugaskan diri sendiri.']);

        $this->assertSame($before, $this->footprint());
    }

    public function test_the_create_page_offers_the_technicians_of_the_queue_with_their_workload(): void
    {
        $this->actingAs($this->admin);
        $this->workOrderOf($this->technician, 'assigned');
        $this->workOrderOf($this->technician, 'completed');

        $page = $this->get(route('admin.customers.create'))->assertOk()->viewData('page')['props'];

        $this->assertTrue($page['can']['assignTechnician']);
        $this->assertSame([['id' => $this->technician->public_id, 'name' => 'Budi Teknisi', 'load' => 1]], $page['technicians']);
        $this->assertStringNotContainsString('@', (string) json_encode($page['technicians']), 'a name and a workload, no email');
    }

    public function test_resending_the_access_link_promises_only_what_is_queued(): void
    {
        $this->register()->assertRedirect();
        $customer = Customer::query()->where('code', 'CUS-ASSIGN')->sole();

        $this->post(route('admin.customers.access-link', $customer))
            ->assertRedirect()
            ->assertSessionHas('success', 'Tautan akses akan dikirim ke customer.assign@example.test.');
    }

    public function test_the_geocoder_gives_the_street_line_of_the_address_form(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['display_name' => 'Jalan Merdeka 12, Bandung', 'lat' => '-6.9', 'lon' => '107.6', 'address' => ['road' => 'Jalan Merdeka', 'house_number' => '12', 'postcode' => '40111']],
            ['display_name' => 'Jalan Asia Afrika, Bandung', 'lat' => '-6.92', 'lon' => '107.61', 'address' => ['road' => 'Jalan Asia Afrika']],
            ['display_name' => 'Antapani, Bandung', 'lat' => '-6.91', 'lon' => '107.66', 'address' => ['suburb' => 'Antapani']],
        ])]);

        $streets = array_column(app(OpenStreetMapGeocoder::class)->search('Jalan Merdeka 12 Bandung'), 'street');

        $this->assertSame(['Jalan Merdeka 12', 'Jalan Asia Afrika', ''], $streets);
    }

    private function companyDay(int $offset): string
    {
        return now($this->company->timezone ?: config('app.timezone'))->addDays($offset)->toDateString();
    }

    /** @param array<string, mixed> $attributes */
    private function staff(array $attributes = []): User
    {
        return User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function workOrderOf(User $technician, string $status): WorkOrder
    {
        return WorkOrder::forceCreate([
            'company_id' => $this->company->id,
            'code' => 'SPK-'.fake()->unique()->numerify('#####'),
            'type' => 'maintenance',
            'title' => 'Ganti kabel',
            'status' => $status,
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'assigned_to' => $technician->id,
        ]);
    }

    /** @return array<string, int> what a refused registration must not move */
    private function footprint(): array
    {
        return [
            'customers' => DB::table('customers')->count(),
            'users' => DB::table('users')->where('email', 'customer.assign@example.test')->count(),
            'subscriptions' => DB::table('service_subscriptions')->count(),
            'work_orders' => DB::table('work_orders')->count(),
            'assignments' => DB::table('work_order_assignments')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra  top-level keys of the form
     * @param  array<string, mixed>  $subscription  extra subscription keys
     */
    private function register(array $extra = [], array $subscription = [], bool $json = false, ?User $as = null): TestResponse
    {
        $this->actingAs($as ?? $this->admin);
        $payload = $this->payload($extra, $subscription);

        return $json
            ? $this->postJson(route('admin.customers.store'), $payload)
            : $this->post(route('admin.customers.store'), $payload);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $subscription
     * @return array<string, mixed>
     */
    private function payload(array $extra, array $subscription): array
    {
        return [
            'code' => 'CUS-ASSIGN',
            'name' => 'Pelanggan Tugas',
            'type' => 'Individual',
            'email' => 'customer.assign@example.test',
            'phone' => '0800000002',
            'is_active' => true,
            'addresses' => [[
                'label' => 'Pemasangan',
                'address' => 'Jalan Pemasangan 2',
                'city' => 'Jakarta',
                'postal_code' => '12346',
                'province_code' => '31',
                'city_code' => '3171',
                'district_code' => '3171010',
                'village_code' => '3171010001',
                'lat' => -6.201,
                'lng' => 106.817,
                'is_primary' => true,
                'is_installation_point' => true,
                'notes' => null,
            ]],
            'contacts' => [[
                'name' => 'Pelanggan Tugas',
                'position' => null,
                'phone' => '0800000002',
                'email' => 'customer.assign@example.test',
                'is_primary' => true,
                'notes' => null,
            ]],
            'subscription' => [
                'service_package_id' => $this->package->public_id,
                'serving_pop_id' => $this->pop->public_id,
                'billing_day' => 5,
                'mrc_amount' => null,
                'otc_installation_fee' => null,
                'contract_months' => null,
                'notes' => null,
                ...$subscription,
            ],
            ...$extra,
        ];
    }

    private function seedRegion(): void
    {
        DB::table('indonesia_provinces')->insert(['code' => '31', 'name' => 'DKI JAKARTA']);
        DB::table('indonesia_cities')->insert(['code' => '3171', 'province_code' => '31', 'name' => 'KOTA JAKARTA SELATAN']);
        DB::table('indonesia_districts')->insert(['code' => '3171010', 'city_code' => '3171', 'name' => 'KEBAYORAN BARU']);
        DB::table('indonesia_villages')->insert(['code' => '3171010001', 'district_code' => '3171010', 'name' => 'GANDARIA UTARA']);
    }
}
