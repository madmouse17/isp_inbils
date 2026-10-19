<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Modules\SPK\Services\SpkService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Spec F1-F4: "Berangkat" is a timestamp inside `assigned` (the assigned technician, once, an optional fix kept as the
 * en_route activity), the detail carries the four steps, and the destination is the customer's installation address.
 */
class WorkOrderTripTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const ASSIGNED_AT = '2026-10-16 01:00:00';

    /** @var array<string, mixed> */
    private const HOME = ['label' => 'Rumah', 'address' => 'Jl. Mawar 1', 'city' => 'Jakarta', 'lat' => -6.2, 'lng' => 106.8166667];

    /** @var array<string, mixed> about 120 km from HOME */
    private const POP = ['name' => 'POP Pusat', 'address' => 'Jl. POP 1', 'lat' => -6.9175, 'lng' => 107.6191];

    private User $technician;

    private string $token;

    private User $customerUser;

    private Customer $customer;

    /** @var array<string, ServiceSubscription> one subscription per installation address, so visits share it */
    private array $subscriptions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = $this->technicianLogin();
        $this->token = $this->issueToken($this->technician, 'technician');
        [$this->customerUser, $this->customer] = $this->customerLogin($this->technician->company);
        $this->customer->forceFill(['phone' => '0812-3456-7890'])->save();
    }

    public function test_going_en_route_stamps_the_visit_and_moves_the_steps(): void
    {
        $workOrder = $this->visit();
        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.current', 'assigned')
            ->assertJsonPath('data.actions.en_route', true);

        $response = $this->api('POST', "technician/work-orders/{$workOrder->public_id}/en-route", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.current', 'en_route')
            ->assertJsonPath('data.actions.en_route', false)
            ->assertJsonPath('data.actions.start', true)
            ->assertJsonPath('data.check_in.status', 'none');

        $steps = $response->json('data.steps');
        $this->assertSame(['assigned', 'en_route', 'in_progress', 'installed'], array_column($steps, 'key'));
        $this->assertNotNull($steps[0]['at']);
        $this->assertNotNull($steps[1]['at']);
        $this->assertNull($steps[2]['at']);
        $this->assertNull($steps[3]['at']);
        $this->assertEqualsWithDelta(now()->timestamp, $workOrder->fresh()->en_route_at->timestamp, 5);
        $this->assertSame(1, $this->activities($workOrder, 'en_route')->count());
    }

    public function test_the_optional_fix_is_kept_as_the_en_route_activity_with_the_distance_to_the_installation_address(): void
    {
        $workOrder = $this->visit();

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/en-route", [
            'check_in' => ['lat' => -6.2, 'lng' => 106.8166667, 'accuracy_m' => 8.5, 'captured_at' => now()->subMinute()->toIso8601String()],
        ], $this->token)->assertOk();

        $properties = json_decode((string) $this->activities($workOrder, 'en_route')->value('properties'), true);
        $this->assertSame('recorded', $properties['status']);
        $this->assertSame(0, $properties['distance_m'], 'measured to the house, not to the POP 120 km away');
        $this->assertSame(8.5, $properties['accuracy_m']);
        $this->assertArrayNotHasKey('company_id', $properties, 'the company lives in its column');
        $this->assertSame($workOrder->company_id, (int) $this->activities($workOrder, 'en_route')->value('company_id'));
        $this->assertSame(0, $this->activities($workOrder, 'check_in')->count(), 'the start check-in stays its own record');
    }

    public function test_a_fix_that_could_not_be_taken_keeps_the_reason(): void
    {
        $workOrder = $this->visit();

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/en-route", ['check_in' => ['unavailable_reason' => 'location_disabled']], $this->token)
            ->assertOk();

        $properties = json_decode((string) $this->activities($workOrder, 'en_route')->value('properties'), true);
        $this->assertSame('unavailable', $properties['status']);
        $this->assertSame('location_disabled', $properties['reason']);
    }

    public function test_an_invalid_fix_is_refused_and_nothing_changes(): void
    {
        $workOrder = $this->visit();

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/en-route", [
            'check_in' => ['lat' => 12, 'lng' => 181, 'captured_at' => now()->addHour()->toIso8601String()],
        ], $this->token)->assertUnprocessable()->assertJsonValidationErrors(['check_in.lng', 'check_in.captured_at']);

        $this->assertNull($workOrder->fresh()->en_route_at);
        $this->assertSame(0, $this->customer->notifications()->count());
    }

    public function test_going_en_route_twice_changes_nothing_and_tells_the_customer_once(): void
    {
        $workOrder = $this->visit();
        $url = "technician/work-orders/{$workOrder->public_id}/en-route";

        $this->api('POST', $url, token: $this->token)->assertOk();
        $first = $workOrder->fresh()->en_route_at;
        $this->travel(10)->minutes();

        $this->api('POST', $url, ['check_in' => ['lat' => -6.2, 'lng' => 106.8]], $this->token)
            ->assertOk()
            ->assertJsonPath('data.current', 'en_route');

        $this->assertTrue($first->equalTo($workOrder->fresh()->en_route_at));
        $this->assertSame(1, $this->activities($workOrder, 'en_route')->count());
        $this->assertSame(1, $this->customer->notifications()->count());
    }

    public function test_one_trip_makes_one_notification_and_a_later_trip_makes_another(): void
    {
        $this->freezeTime();
        $workOrder = $this->visit();
        $url = "technician/work-orders/{$workOrder->public_id}/en-route";
        $ids = fn (): array => $this->customer->notifications()->orderBy('created_at')->pluck('id')->all();

        $this->api('POST', $url, token: $this->token)->assertOk();
        $this->assertCount(1, $ids());
        $first = $ids()[0];

        // The same SPK at the same moment again (its trip cleared and replayed): that trip's row is found, nothing is added.
        $workOrder->fresh()->forceFill(['en_route_at' => null])->save();
        $this->api('POST', $url, token: $this->token)->assertOk();
        $this->assertSame([$first], $ids());

        // A later trip is another event.
        $workOrder->fresh()->forceFill(['en_route_at' => null])->save();
        $this->travel(1)->hours();
        $this->api('POST', $url, token: $this->token)->assertOk();
        $this->assertCount(2, $ids());
        $this->assertContains($first, $ids());
    }

    /** @return array<string, array{0: string}> */
    public static function statusesThatAreNotAssigned(): array
    {
        return [
            'generated' => ['generated'],
            'in progress' => ['in_progress'],
            'waiting for review' => ['waiting_review'],
            'rejected' => ['rejected'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];
    }

    #[DataProvider('statusesThatAreNotAssigned')]
    public function test_going_en_route_is_only_for_an_assigned_visit(string $status): void
    {
        $workOrder = $this->visit($status);

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/en-route", token: $this->token)->assertForbidden();

        $this->assertNull($workOrder->fresh()->en_route_at);
        $this->assertSame(0, $this->customer->notifications()->count());
    }

    public function test_only_the_assigned_technician_may_go_en_route_and_a_stranger_gets_404(): void
    {
        $workOrder = $this->visit();
        $peer = $this->issueToken($this->technicianLogin($this->technician->company), 'technician');
        $stranger = $this->issueToken($this->technicianLogin(), 'technician');
        $url = "technician/work-orders/{$workOrder->public_id}/en-route";

        $this->api('POST', $url, token: $peer)->assertNotFound();
        $this->api('POST', $url, token: $stranger)->assertNotFound();
        $this->api('POST', $url, token: $this->issueToken($this->customerUser, 'customer'))->assertForbidden();

        $this->assertNull($workOrder->fresh()->en_route_at);
        $this->assertSame(0, $this->customer->notifications()->count());
    }

    public function test_the_customer_finds_the_news_in_the_app_and_it_opens_the_visit(): void
    {
        $workOrder = $this->visit();
        $this->technician->forceFill(['name' => 'Andi Wijaya'])->save();

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/en-route", token: $this->token)->assertOk();

        $notification = $this->api('GET', 'notifications', token: $this->issueToken($this->customerUser, 'customer'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame('technician_en_route', $notification['type']);
        $this->assertSame('Teknisi dalam perjalanan', $notification['title']);
        $this->assertSame('Teknisi Andi sedang menuju lokasi Anda.', $notification['message']);
        $this->assertSame(['type' => 'work_order', 'id' => $workOrder->public_id], $notification['subject']);
        $this->assertNull($notification['read_at']);
        $this->assertStringNotContainsString('Wijaya', json_encode($notification, JSON_THROW_ON_ERROR));
    }

    public function test_an_spk_without_a_subscription_or_a_customer_without_a_login_is_not_notified(): void
    {
        $withoutSubscription = $this->visit(subscribed: false);
        $this->api('POST', "technician/work-orders/{$withoutSubscription->public_id}/en-route", token: $this->token)->assertOk();
        $this->assertSame(0, DB::table('notifications')->count(), 'without a subscription the app shows no visit, so there is nothing to open');

        $withoutLogin = $this->visit();
        $this->customer->forceFill(['user_id' => null])->save();
        $this->api('POST', "technician/work-orders/{$withoutLogin->public_id}/en-route", token: $this->token)->assertOk();

        $this->assertSame(0, DB::table('notifications')->count(), 'nobody can read it in the app');
        $this->assertNotNull($withoutSubscription->fresh()->en_route_at);
        $this->assertNotNull($withoutLogin->fresh()->en_route_at);
    }

    public function test_the_detail_carries_the_four_steps_and_the_whatsapp_number_of_the_customer(): void
    {
        $workOrder = $this->visit();

        $data = $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $this->token)->assertOk()->json('data');

        $this->assertSame([
            ['key' => 'assigned', 'label' => 'Ditugaskan', 'at' => Carbon::parse(self::ASSIGNED_AT)->jsonSerialize()],
            ['key' => 'en_route', 'label' => 'Dalam perjalanan', 'at' => null],
            ['key' => 'in_progress', 'label' => 'Sedang dikerjakan', 'at' => null],
            ['key' => 'installed', 'label' => 'Selesai dipasang', 'at' => null],
        ], $data['steps']);
        $this->assertSame('0812-3456-7890', $data['customer']['phone']);
        $this->assertSame('+6281234567890', $data['customer']['phone_e164']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function types(): array
    {
        return [
            'installation' => ['installation', 'Instalasi'],
            'maintenance' => ['maintenance', 'Perawatan'],
            'upgrade' => ['upgrade_service', 'Peningkatan layanan'],
            'relocation' => ['relocation', 'Pindah lokasi'],
        ];
    }

    #[DataProvider('types')]
    public function test_the_detail_names_the_type_in_indonesian_next_to_its_key(string $type, string $label): void
    {
        $workOrder = $this->visit('assigned', ['type' => $type]);

        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.type_label', $label);
    }

    public function test_a_number_whatsapp_cannot_reach_has_no_e164(): void
    {
        $this->customer->forceFill(['phone' => '021-5551234'])->save();
        $workOrder = $this->visit();

        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.customer.phone', '021-5551234')
            ->assertJsonPath('data.customer.phone_e164', null);
    }

    public function test_the_site_is_the_installation_address_and_the_pop_is_shown_apart(): void
    {
        $workOrder = $this->visit();

        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.site', ['label' => 'Rumah', 'address' => 'Jl. Mawar 1, Jakarta', 'lat' => -6.2, 'lng' => 106.8166667])
            ->assertJsonPath('data.pop', ['label' => 'POP Pusat', 'address' => 'Jl. POP 1', 'lat' => -6.9175, 'lng' => 107.6191]);
    }

    public function test_an_spk_without_a_subscription_keeps_its_location_as_the_site(): void
    {
        $workOrder = $this->visit(subscribed: false);

        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.site', ['label' => 'POP Pusat', 'address' => 'Jl. POP 1', 'lat' => -6.9175, 'lng' => 107.6191])
            ->assertJsonPath('data.pop', null);
    }

    public function test_an_address_without_coordinates_keeps_its_text_and_never_borrows_the_pop(): void
    {
        $workOrder = $this->visit(address: [...self::HOME, 'lat' => null, 'lng' => null]);

        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.site', ['label' => 'Rumah', 'address' => 'Jl. Mawar 1, Jakarta', 'lat' => null, 'lng' => null])
            ->assertJsonPath('data.pop.label', 'POP Pusat');
    }

    public function test_the_start_distance_is_measured_to_the_installation_address_not_the_pop(): void
    {
        $atHome = $this->visit();
        $atPop = $this->visit();

        $this->api('POST', "technician/work-orders/{$atHome->public_id}/start", ['check_in' => ['lat' => -6.2, 'lng' => 106.8166667]], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.distance_m', 0);
        $response = $this->api('POST', "technician/work-orders/{$atPop->public_id}/start", ['check_in' => ['lat' => -6.9175, 'lng' => 107.6191]], $this->token)->assertOk();

        $this->assertGreaterThan(100_000, $response->json('data.check_in.distance_m'), 'standing at the POP is far from the customer');
    }

    public function test_an_address_without_coordinates_gives_no_distance_instead_of_the_pops(): void
    {
        $workOrder = $this->visit(address: [...self::HOME, 'lat' => null, 'lng' => null]);

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/start", ['check_in' => ['lat' => -6.9175, 'lng' => 107.6191]], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.status', 'recorded')
            ->assertJsonPath('data.check_in.distance_m', null);
    }

    public function test_an_spk_without_a_subscription_measures_the_start_to_its_location(): void
    {
        $workOrder = $this->visit(subscribed: false);

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/start", ['check_in' => ['lat' => -6.9175, 'lng' => 107.6191]], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.distance_m', 0);
    }

    public function test_submitting_stamps_the_finish_and_reaches_the_last_step(): void
    {
        Storage::fake('local');
        $workOrder = $this->visit('in_progress', ['started_at' => now()->subHour()]);
        $this->attachEvidence($workOrder);

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/submit", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.current', 'installed')
            ->assertJsonPath('data.steps.3.key', 'installed');

        $this->assertEqualsWithDelta(now()->timestamp, $workOrder->fresh()->submitted_at->timestamp, 5);
    }

    public function test_a_reworked_visit_starts_every_step_over_and_finishes_after_it_restarted(): void
    {
        Storage::fake('local');
        Notification::fake();
        $admin = User::factory()->create(['company_id' => $this->technician->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole('admin');
        $workOrder = $this->visit();
        $this->attachEvidence($workOrder);
        $url = fn (string $action): string => "technician/work-orders/{$workOrder->public_id}/{$action}";

        // First run: on the way, started, finished.
        $this->api('POST', $url('en-route'), token: $this->token)->assertOk();
        $this->travel(1)->hours();
        $this->api('POST', $url('start'), token: $this->token)->assertOk();
        $this->travel(1)->hours();
        $this->api('POST', $url('submit'), token: $this->token)->assertOk();
        $firstFinish = $workOrder->fresh()->submitted_at;

        // The office sends it back and gives it to the same technician again.
        $this->travel(1)->hours();
        $this->actingAs($admin);
        SpkService::reject($workOrder->fresh(), 'Foto kurang jelas');
        $this->travel(1)->hours();
        $rejected = $workOrder->fresh();
        SpkService::assign($rejected, $this->technician->id, $admin->id, $this->technician->id, $rejected->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'));

        $afterReassign = $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $this->token)->assertOk();
        $this->assertSame(['assigned', 'assigned'], [$afterReassign->json('data.status'), $afterReassign->json('data.current')]);
        $this->assertNull($afterReassign->json('data.started_at'), 'no "Dimulai" of the earlier run above a stepper that begins again');
        $this->assertSame([null, null, null], array_column(array_slice($afterReassign->json('data.steps'), 1), 'at'));
        $this->assertNull($workOrder->fresh()->en_route_at);
        $this->assertNull($workOrder->fresh()->started_at);
        $this->assertNull($workOrder->fresh()->submitted_at, 'the earlier finish belongs to the earlier run');
        $this->assertSame(1, $this->activities($workOrder, 'started')->count(), 'the first start stays in the trail');

        // Second run: started and finished again, without going en route.
        $this->travel(1)->hours();
        $this->api('POST', $url('start'), token: $this->token)->assertOk();
        $this->travel(2)->hours();
        $this->api('POST', $url('submit'), token: $this->token)->assertOk();

        $detail = $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $this->token)->assertOk()->json('data');
        $steps = collect($detail['steps'])->keyBy('key');
        $at = fn (string $key): ?Carbon => $steps[$key]['at'] === null ? null : Carbon::parse($steps[$key]['at']);

        $this->assertNull($at('en_route'), 'the first trip is not this run\'s');
        $this->assertTrue($at('installed')->gte($at('in_progress')), 'a finish never precedes its restart');
        $this->assertTrue($at('in_progress')->gt($firstFinish));
        $this->assertTrue($at('installed')->gt($firstFinish));
        $this->assertTrue($at('assigned')->gt($firstFinish), 'the new assignment, not the first one');
        $this->assertSame($steps['in_progress']['at'], $detail['started_at'], 'the stepper and the start row tell the same time');
        $this->assertSame(2, $this->activities($workOrder, 'started')->count());
    }

    private function attachEvidence(WorkOrder $workOrder): void
    {
        $workOrder->addMediaFromString('proof')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $workOrder->company_id, 'type' => 'photo', 'uploaded_by' => $this->technician->id])
            ->toMediaCollection('evidence');
    }

    /**
     * @param  array<string, mixed>  $columns  work_orders columns
     * @param  array<string, mixed>|null  $address  installation address columns
     * @param  array<string, mixed>|null  $pop  location columns
     */
    private function visit(string $status = 'assigned', array $columns = [], ?array $address = self::HOME, ?array $pop = self::POP, bool $subscribed = true): WorkOrder
    {
        $company = $this->technician->company;
        CompanyService::useCompanyForTests((int) $company->id);

        $subscription = $subscribed ? ($this->subscriptions[md5(serialize($address))] ??= ServiceSubscription::factory()->create([
            'customer_id' => $this->customer->id,
            'service_package_id' => ServicePackage::factory()->create(['company_id' => $company->id])->id,
            'installation_address_id' => CustomerAddress::factory()->create([
                'company_id' => $company->id,
                'customer_id' => $this->customer->id,
                'is_installation_point' => true,
                ...$address,
            ])->id,
        ])) : null;
        $location = $pop === null ? null : Location::factory()->create(['company_id' => $company->id, ...$pop]);

        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => $status,
            'assigned_to' => $this->technician->id,
            'customer_id' => $this->customer->id,
            'subscription_id' => $subscription?->id,
            'location_id' => $location?->id,
            'created_by' => $this->technician->id,
            ...$columns,
        ]);
        WorkOrderAssignment::forceCreate([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'technician_id' => $this->technician->id,
            'assigned_by' => $this->technician->id,
            'assigned_at' => self::ASSIGNED_AT,
        ]);

        return $workOrder;
    }

    private function activities(WorkOrder $workOrder, string $description): Builder
    {
        return DB::table('activity_log')
            ->where('log_name', 'work_order')
            ->where('description', $description)
            ->where('subject_id', $workOrder->id);
    }
}
