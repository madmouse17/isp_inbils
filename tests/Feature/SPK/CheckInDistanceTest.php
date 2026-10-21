<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\AuditProperties;
use App\Support\FieldCheckIn;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * K10: a technician who starts the work farther from the customer's address than the company allows (default 500 m, the
 * setting `checkin_max_distance_m`) says why, or the start is refused on `check_in.far_reason` and writes nothing. The check-in
 * of such a start is flagged `far` with the reason. Announcing the trip ("Berangkat") is free of the limit.
 */
class CheckInDistanceTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    /** @var array{lat: float, lng: float} the customer's installation address */
    private const HOME = ['lat' => -6.2, 'lng' => 106.8166667];

    /** About 445 m north of HOME. */
    private const NEAR = ['lat' => -6.196, 'lng' => 106.8166667];

    /** About 667 m north of HOME. */
    private const OUT = ['lat' => -6.194, 'lng' => 106.8166667];

    /** About 1,112 m north of HOME. */
    private const FAR = ['lat' => -6.19, 'lng' => 106.8166667];

    private User $technician;

    private string $token;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $company = $this->apiCompany();
        CompanyService::useCompanyForTests((int) $company->id);
        $this->technician = $this->technicianLogin($company);
        $this->token = $this->issueToken($this->technician, 'technician');
        $this->customer = Customer::factory()->create(['company_id' => $company->id]);
    }

    public function test_the_distances_of_the_fixtures_sit_where_the_tests_expect_them(): void
    {
        $distance = fn (array $at): int => FieldCheckIn::properties($at, self::HOME)['distance_m'];

        $this->assertSame([445, 667, 1112], [$distance(self::NEAR), $distance(self::OUT), $distance(self::FAR)]);
    }

    public function test_a_far_start_of_an_spk_without_a_reason_is_refused_whole_on_the_reason_field(): void
    {
        $spk = $this->visit();

        $refused = $this->api('POST', "technician/work-orders/{$spk->public_id}/start", ['check_in' => self::FAR], $this->token)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in.far_reason']);
        $this->assertSame('Anda berjarak 1,1 km dari lokasi pelanggan. Tulis alasannya untuk melanjutkan.', $refused->json('errors')['check_in.far_reason'][0]);

        $this->assertSame('assigned', $spk->fresh()->status);
        $this->assertNull($spk->fresh()->started_at);
        $this->assertSame(0, $this->activities($spk, 'started')->count());
        $this->assertSame(0, $this->activities($spk, 'check_in')->count(), 'a refused start leaves no check-in behind');
    }

    public function test_a_far_start_of_an_spk_with_a_reason_goes_through_flagged(): void
    {
        $spk = $this->visit();

        $this->api('POST', "technician/work-orders/{$spk->public_id}/start", ['check_in' => [...self::FAR, 'far_reason' => '  Alamat di peta salah, rumahnya di gang belakang  ']], $this->token)
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.check_in.status', 'recorded')
            ->assertJsonPath('data.check_in.distance_m', 1112)
            ->assertJsonPath('data.check_in.far', true)
            ->assertJsonPath('data.check_in.far_reason', 'Alamat di peta salah, rumahnya di gang belakang');

        $properties = json_decode((string) $this->activities($spk, 'check_in')->value('properties'), true);
        $this->assertTrue($properties['far']);
        $this->assertSame('Alamat di peta salah, rumahnya di gang belakang', $properties['far_reason']);
    }

    public function test_a_reason_of_only_blanks_is_no_reason(): void
    {
        $spk = $this->visit();

        $this->api('POST', "technician/work-orders/{$spk->public_id}/start", ['check_in' => [...self::FAR, 'far_reason' => '   ']], $this->token)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in.far_reason']);
        $this->assertSame('assigned', $spk->fresh()->status);
    }

    public function test_a_start_within_the_limit_needs_no_reason_and_keeps_none(): void
    {
        $spk = $this->visit();

        $this->api('POST', "technician/work-orders/{$spk->public_id}/start", ['check_in' => [...self::NEAR, 'far_reason' => 'Tidak perlu']], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.distance_m', 445)
            ->assertJsonPath('data.check_in.far', false)
            ->assertJsonPath('data.check_in.far_reason', null);

        $properties = json_decode((string) $this->activities($spk, 'check_in')->value('properties'), true);
        $this->assertFalse($properties['far']);
        $this->assertNull($properties['far_reason'], 'a reason for a start that was never far is not kept');
    }

    public function test_the_limit_is_five_hundred_metres_until_the_company_sets_its_own(): void
    {
        $this->assertSame(500, FieldCheckIn::DEFAULT_MAX_DISTANCE_M);
        $this->assertSame(500, FieldCheckIn::maxDistanceMetres());

        $this->api('POST', "technician/work-orders/{$this->visit()->public_id}/start", ['check_in' => self::OUT], $this->token)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in.far_reason']);
    }

    public function test_the_company_setting_moves_the_limit_and_the_limit_itself_is_not_far(): void
    {
        $this->companySettings(['checkin_max_distance_m' => 2000]);
        $this->api('POST', "technician/work-orders/{$this->visit()->public_id}/start", ['check_in' => self::FAR], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.far', false);

        $this->companySettings(['checkin_max_distance_m' => 445]);
        $this->api('POST', "technician/work-orders/{$this->visit()->public_id}/start", ['check_in' => self::NEAR], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.far', false);

        $this->companySettings(['checkin_max_distance_m' => 444]);
        $this->api('POST', "technician/work-orders/{$this->visit()->public_id}/start", ['check_in' => self::NEAR], $this->token)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in.far_reason']);
    }

    public function test_another_company_keeps_its_own_limit(): void
    {
        $other = $this->apiCompany();
        $other->forceFill(['settings' => ['checkin_max_distance_m' => 5000]])->save();
        CompanyService::resetCache();
        CompanyService::useCompanyForTests((int) $this->technician->company_id);

        $this->api('POST', "technician/work-orders/{$this->visit()->public_id}/start", ['check_in' => self::FAR], $this->token)
            ->assertUnprocessable();
    }

    public function test_announcing_the_trip_is_free_of_the_limit(): void
    {
        $spk = $this->visit();

        $this->api('POST', "technician/work-orders/{$spk->public_id}/en-route", ['check_in' => [...self::FAR, 'far_reason' => 'Dari kantor']], $this->token)->assertOk();
        $this->api('POST', "technician/work-orders/{$this->visit()->public_id}/en-route", ['check_in' => self::FAR], $this->token)->assertOk();

        $properties = json_decode((string) $this->activities($spk, 'en_route')->value('properties'), true);
        $this->assertSame(1112, $properties['distance_m']);
        $this->assertArrayNotHasKey('far', $properties, 'the trip is only announced: nothing is flagged');
        $this->assertSame(0, $this->activities($spk, 'check_in')->count());
    }

    public function test_a_fix_that_could_not_be_taken_and_a_site_without_coordinates_need_no_reason(): void
    {
        $this->api('POST', "technician/work-orders/{$this->visit()->public_id}/start", ['check_in' => ['unavailable_reason' => 'no_fix']], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.status', 'unavailable')
            ->assertJsonPath('data.check_in.far', false);

        $this->api('POST', "technician/work-orders/{$this->visit(withCoordinates: false)->public_id}/start", ['check_in' => self::FAR], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.status', 'recorded')
            ->assertJsonPath('data.check_in.distance_m', null)
            ->assertJsonPath('data.check_in.far', false);
    }

    public function test_a_start_without_a_check_in_or_with_an_empty_one_is_not_asked_for_a_reason(): void
    {
        $this->api('POST', "technician/work-orders/{$this->visit()->public_id}/start", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.status', 'none');

        $this->api('POST', "technician/work-orders/{$this->visit()->public_id}/start", ['check_in' => []], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.status', 'none');
    }

    public function test_the_reason_is_at_most_three_hundred_characters(): void
    {
        $spk = $this->visit();

        $this->api('POST', "technician/work-orders/{$spk->public_id}/start", ['check_in' => [...self::FAR, 'far_reason' => str_repeat('a', 301)]], $this->token)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in.far_reason']);

        $this->api('POST', "technician/work-orders/{$spk->public_id}/start", ['check_in' => [...self::FAR, 'far_reason' => str_repeat('a', 300)]], $this->token)
            ->assertOk();
    }

    public function test_a_far_start_of_a_ticket_follows_the_same_rule(): void
    {
        $ticket = $this->ticket();

        $refused = $this->api('POST', "technician/tickets/{$ticket->public_id}/start", ['check_in' => self::FAR], $this->token)
            ->assertUnprocessable();
        $this->assertSame('Anda berjarak 1,1 km dari lokasi pelanggan. Tulis alasannya untuk melanjutkan.', $refused->json('errors')['check_in.far_reason'][0]);

        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->first_response_at, 'a refused start does not count as the first response');
        $this->assertSame(0, Activity::query()->where('log_name', 'ticket')->whereIn('description', ['started', 'check_in'])->count());

        $this->api('POST', "technician/tickets/{$ticket->public_id}/start", ['check_in' => [...self::FAR, 'far_reason' => 'Pelanggan minta ketemu di warung']], $this->token)
            ->assertOk()
            ->assertJsonPath('data.status', 'on_progress')
            ->assertJsonPath('data.check_in.far', true)
            ->assertJsonPath('data.check_in.far_reason', 'Pelanggan minta ketemu di warung');

        $audit = Activity::query()->where('log_name', 'ticket')->where('description', 'check_in')->sole();
        $this->assertTrue($audit->properties['far']);
        $this->assertSame('Pelanggan minta ketemu di warung', $audit->properties['far_reason']);
    }

    public function test_the_audit_log_and_its_file_say_how_far_the_technician_was_and_never_where_they_stood(): void
    {
        $spk = $this->visit();
        $this->api('POST', "technician/work-orders/{$spk->public_id}/en-route", ['check_in' => self::FAR], $this->token)->assertOk();
        $this->api('POST', "technician/work-orders/{$spk->public_id}/start", ['check_in' => [...self::FAR, 'far_reason' => 'Gang belakang', 'accuracy_m' => 9]], $this->token)->assertOk();
        $this->api('POST', "technician/tickets/{$this->ticket()->public_id}/start", ['check_in' => [...self::FAR, 'far_reason' => 'Warung']], $this->token)->assertOk();

        $this->actingAs($this->settingsAdmin());
        $rows = collect($this->get(route('admin.reports.audit-log'))->assertOk()->inertiaProps('data.data'))
            ->filter(fn (array $row): bool => in_array($row['description'], ['en_route', 'check_in'], true));

        $this->assertCount(3, $rows, 'the trip, the start of the SPK and the start of the ticket');
        foreach ($rows as $row) {
            $this->assertSame([AuditProperties::MASKED, AuditProperties::MASKED], [$row['properties']['lat'], $row['properties']['lng']], $row['description']);
            $this->assertSame(1112, $row['properties']['distance_m'], 'how far stays readable');
        }
        $checkIns = $rows->where('description', 'check_in');
        $this->assertEqualsCanonicalizing(['Gang belakang', 'Warung'], $checkIns->pluck('properties.far_reason')->all());
        $this->assertTrue($checkIns->every(fn (array $row): bool => $row['properties']['far'] === true));

        $csv = $this->get(route('admin.reports.audit-log.export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('106.8166667', $csv);
        $this->assertStringNotContainsString('-6.19', $csv);
        $this->assertStringContainsString('distance_m', $csv);
        $this->assertStringContainsString('Gang belakang', $csv, 'the reason is part of the entry');

        // Only the screen and the file hide the fix: the stored row keeps it.
        $this->assertSame(-6.19, Activity::query()->where('log_name', 'ticket')->where('description', 'check_in')->sole()->properties['lat']);
    }

    public function test_a_ticket_start_within_the_limit_and_without_a_check_in_needs_no_reason(): void
    {
        $this->api('POST', "technician/tickets/{$this->ticket()->public_id}/start", ['check_in' => self::NEAR], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.far', false);

        $this->api('POST', "technician/tickets/{$this->ticket()->public_id}/start", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.status', 'none');
    }

    public function test_a_far_ticket_start_is_measured_to_the_installation_address_and_the_limit_is_the_companys(): void
    {
        $this->companySettings(['checkin_max_distance_m' => 100_000]);

        $this->api('POST', "technician/tickets/{$this->ticket()->public_id}/start", ['check_in' => self::FAR], $this->token)
            ->assertOk()
            ->assertJsonPath('data.check_in.distance_m', 1112)
            ->assertJsonPath('data.check_in.far', false);
    }

    public function test_the_admin_pages_carry_the_flag_and_the_reason_and_never_the_coordinates(): void
    {
        $spk = $this->visit();
        $this->api('POST', "technician/work-orders/{$spk->public_id}/start", ['check_in' => [...self::FAR, 'far_reason' => 'Gang belakang', 'accuracy_m' => 9]], $this->token)->assertOk();
        $admin = User::factory()->create(['company_id' => $this->technician->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($admin, 'admin', $this->technician->company);

        $page = $this->actingAs($admin)->get(route('admin.spk.show', $spk))->assertOk()->viewData('page')['props']['checkIn'];

        $this->assertEquals(['status' => 'recorded', 'distance_m' => 1112, 'accuracy_m' => 9, 'far' => true, 'far_reason' => 'Gang belakang'], $page);
    }

    public function test_the_page_summary_keeps_only_what_a_page_words_and_an_old_record_has_no_flag(): void
    {
        $this->assertNull(FieldCheckIn::pageSummary(null));
        $this->assertEquals(
            ['status' => 'recorded', 'distance_m' => 700, 'accuracy_m' => 5.0, 'far' => true, 'far_reason' => 'Macet'],
            FieldCheckIn::pageSummary(['status' => 'recorded', 'lat' => -6.2, 'lng' => 106.8, 'accuracy_m' => 5.0, 'captured_at' => null, 'distance_m' => 700, 'far' => true, 'far_reason' => 'Macet']),
        );
        $this->assertSame(
            ['status' => 'recorded', 'distance_m' => 12],
            FieldCheckIn::pageSummary(['status' => 'recorded', 'lat' => -6.2, 'lng' => 106.8, 'distance_m' => 12]),
            'a check-in made before the limit has neither key',
        );
        $this->assertSame(['status' => 'unavailable', 'reason' => 'timeout'], FieldCheckIn::pageSummary(['status' => 'unavailable', 'reason' => 'timeout']));
    }

    public function test_the_distance_reads_in_metres_below_a_kilometre_and_in_kilometres_from_it(): void
    {
        $this->assertSame(['0 m', '820 m', '999 m', '1,0 km', '1,2 km', '119,3 km', '1.250,0 km'], array_map(
            FieldCheckIn::distanceText(...),
            [0, 820, 999, 1000, 1150, 119_300, 1_250_000],
        ));
    }

    public function test_the_settings_form_takes_a_limit_of_fifty_metres_to_a_hundred_kilometres(): void
    {
        $admin = $this->settingsAdmin();
        $save = fn (mixed $limit) => $this->actingAs($admin)->put(route('admin.company.settings.update'), [
            'settings' => ['checkin_max_distance_m' => $limit],
            'expected_updated_at' => $this->version(),
        ]);

        foreach ([49, 100_001, 'dekat', '', 12.5] as $bad) {
            $save($bad)->assertSessionHasErrors('settings.checkin_max_distance_m');
        }
        $this->assertArrayNotHasKey('checkin_max_distance_m', $this->technician->company->fresh()->settings ?? []);

        $save('50')->assertSessionHasNoErrors();
        $this->assertSame(50, $this->technician->company->fresh()->settings['checkin_max_distance_m'], 'stored as a number');
        $save(100_000)->assertSessionHasNoErrors();
        $this->assertSame(100_000, $this->technician->company->fresh()->settings['checkin_max_distance_m']);
    }

    public function test_the_settings_page_offers_the_default_limit_until_one_is_saved(): void
    {
        $admin = $this->settingsAdmin();

        $this->actingAs($admin)->get(route('admin.company.settings.edit'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.checkin_max_distance_m', 500));

        $this->companySettings(['checkin_max_distance_m' => 750]);
        $this->actingAs($admin)->get(route('admin.company.settings.edit'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.checkin_max_distance_m', 750));
    }

    private function settingsAdmin(): User
    {
        $admin = User::factory()->create(['company_id' => $this->technician->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($admin, 'admin', $this->technician->company);

        return $admin;
    }

    private function version(): string
    {
        $company = $this->technician->company->fresh();

        return $company->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @param  array<string, mixed>  $settings */
    private function companySettings(array $settings): void
    {
        $company = $this->technician->company;
        $company->forceFill(['settings' => [...($company->settings ?? []), ...$settings]])->save();
        CompanyService::resetCache();
        CompanyService::useCompanyForTests((int) $company->id);
    }

    /** An installation visit at HOME that the technician may start: the POP stands 120 km away and is never the site. */
    private function visit(bool $withCoordinates = true): WorkOrder
    {
        $company = $this->technician->company;
        $address = CustomerAddress::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'is_installation_point' => true,
            'label' => uniqid('Rumah '),
            ...($withCoordinates ? self::HOME : ['lat' => null, 'lng' => null]),
        ]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'service_package_id' => ServicePackage::factory()->create(['company_id' => $company->id])->id,
            'installation_address_id' => $address->id,
        ]);
        $pop = Location::factory()->create(['company_id' => $company->id, 'lat' => -6.9175, 'lng' => 107.6191]);

        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'assigned',
            'assigned_to' => $this->technician->id,
            'customer_id' => $this->customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $pop->id,
            'created_by' => $this->technician->id,
        ]);
        WorkOrderAssignment::forceCreate([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'technician_id' => $this->technician->id,
            'assigned_by' => $this->technician->id,
            'assigned_at' => '2026-10-16 01:00:00',
        ]);

        return $workOrder;
    }

    private function ticket(): Ticket
    {
        $company = $this->technician->company;
        $address = CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $this->customer->id, 'label' => uniqid('Rumah '), ...self::HOME]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'installation_address_id' => $address->id,
            'service_package_id' => ServicePackage::factory()->create(['company_id' => $company->id])->id,
            'status' => 'active',
        ]);
        $category = TicketCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return Ticket::query()->create([
            'company_id' => $company->id,
            'code' => 'TKT-'.uniqid(),
            'title' => 'Internet mati',
            'source' => 'internal',
            'category_id' => $category->id,
            'status' => 'assigned',
            'priority' => 'medium',
            'customer_id' => $this->customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => Location::factory()->create(['company_id' => $company->id, 'lat' => -6.9175, 'lng' => 107.6191])->id,
            'assigned_to' => $this->technician->id,
            'created_by' => $this->technician->id,
            'sla_deadline' => now()->addDay(),
        ]);
    }

    private function activities(WorkOrder $workOrder, string $description): Builder
    {
        return DB::table('activity_log')
            ->where('log_name', 'work_order')
            ->where('description', $description)
            ->where('subject_id', $workOrder->id);
    }
}
