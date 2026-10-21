<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\OptimisticLock;
use App\Support\ServiceHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\SLATier;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * K11: a tier on the business calendar counts the open hours of the company, written in the company's own time zone; a tier on
 * the 24 hour calendar and every category count every hour. A company that never set its hours counts 24 hours whatever the tier
 * asks, and the ticket says so. 2026-03-02 is a Monday; every moment below is Jakarta time (UTC+7).
 */
class BusinessHoursDeadlineTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    /** Monday to Friday 08:00 to 17:00, Saturday 08:00 to 12:00, Sunday closed. */
    private const OFFICE = [
        'mon' => ['08:00', '17:00'],
        'tue' => ['08:00', '17:00'],
        'wed' => ['08:00', '17:00'],
        'thu' => ['08:00', '17:00'],
        'fri' => ['08:00', '17:00'],
        'sat' => ['08:00', '12:00'],
        'sun' => null,
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->actingAs($this->admin);
    }

    public function test_a_ticket_made_after_closing_is_counted_from_the_next_opening(): void
    {
        $this->openOffice();
        $this->travelToJakarta('2026-03-02 18:30');
        $subscription = $this->subscriptionOnTier(responseHours: 2, resolutionHours: 6, calendar: 'business');

        $deadlines = TicketService::computeDeadlines($this->ticketOf($subscription));

        $this->assertSame('2026-03-03 10:00', $this->jakarta($deadlines['response']));
        $this->assertSame('2026-03-03 14:00', $this->jakarta($deadlines['resolution']));
    }

    public function test_a_ticket_made_inside_the_hours_counts_from_its_creation_and_spills_over_the_night(): void
    {
        $this->openOffice();
        $this->travelToJakarta('2026-03-02 15:00');
        $subscription = $this->subscriptionOnTier(responseHours: 2, resolutionHours: 6, calendar: 'business');

        $deadlines = TicketService::computeDeadlines($this->ticketOf($subscription));

        $this->assertSame('2026-03-02 17:00', $this->jakarta($deadlines['response']), 'a target that runs out at closing time ends then');
        $this->assertSame('2026-03-03 12:00', $this->jakarta($deadlines['resolution']), 'two hours today, four tomorrow');
    }

    public function test_priority_urgent_halves_the_service_hours_rounding_up(): void
    {
        $this->openOffice();
        $this->travelToJakarta('2026-03-02 09:00');
        $subscription = $this->subscriptionOnTier(responseHours: 3, resolutionHours: 7, calendar: 'business');

        $deadlines = TicketService::computeDeadlines($this->ticketOf($subscription, priority: 'urgent'));

        $this->assertSame('2026-03-02 11:00', $this->jakarta($deadlines['response']));
        $this->assertSame('2026-03-02 13:00', $this->jakarta($deadlines['resolution']));
    }

    public function test_a_tier_on_the_24_hour_calendar_keeps_counting_every_hour_even_when_the_company_set_its_hours(): void
    {
        $this->openOffice();
        $this->travelToJakarta('2026-03-02 18:30');
        $subscription = $this->subscriptionOnTier(responseHours: 2, resolutionHours: 6, calendar: '24h');
        $ticket = $this->ticketOf($subscription);

        $deadlines = TicketService::computeDeadlines($ticket);

        $this->assertSame('2026-03-02 20:30', $this->jakarta($deadlines['response']));
        $this->assertSame('2026-03-03 00:30', $this->jakarta($deadlines['resolution']));
        $this->assertSame('24h', TicketService::deadlineSource($ticket)['calendar']);
    }

    public function test_a_category_always_counts_every_hour(): void
    {
        $this->openOffice();
        $this->travelToJakarta('2026-03-02 18:30');
        $category = $this->category(slaHours: 6, responseHours: 2);
        $ticket = new Ticket(['category_id' => $category->id, 'priority' => 'medium']);

        $deadlines = TicketService::computeDeadlines($ticket);

        $this->assertSame('2026-03-02 20:30', $this->jakarta($deadlines['response']));
        $this->assertSame('2026-03-03 00:30', $this->jakarta($deadlines['resolution']));
        $this->assertSame('24h', TicketService::deadlineSource($ticket)['calendar']);
    }

    public function test_a_business_tier_of_a_company_without_service_hours_counts_24_hours_and_says_so(): void
    {
        $this->travelToJakarta('2026-03-02 18:30');
        $subscription = $this->subscriptionOnTier(responseHours: 2, resolutionHours: 6, calendar: 'business');
        $ticket = $this->ticketOf($subscription);

        $this->assertSame('2026-03-02 20:30', $this->jakarta(TicketService::computeDeadlines($ticket)['response']));
        $this->assertSame('24h', TicketService::deadlineSource($ticket)['calendar'], 'the ticket page must not promise service hours that are not counted');

        CompanyService::updateSettings(['service_hours' => array_fill_keys(ServiceHours::DAYS, null)]);

        $this->assertSame('2026-03-02 20:30', $this->jakarta(TicketService::computeDeadlines($ticket)['response']), 'a week with every day closed is a week never set');
        $this->assertSame('24h', TicketService::deadlineSource($ticket)['calendar']);
    }

    public function test_the_source_names_the_calendar_that_counted(): void
    {
        $this->openOffice();
        $this->travelToJakarta('2026-03-02 09:00');
        $subscription = $this->subscriptionOnTier(responseHours: 2, resolutionHours: 6, calendar: 'business', tierName: 'Jam Kerja');

        $this->assertSame(
            ['type' => 'tier', 'name' => 'Jam Kerja', 'response_hours' => 2, 'resolution_hours' => 6, 'urgent_halved' => false, 'calendar' => 'business'],
            TicketService::deadlineSource($this->ticketOf($subscription)),
        );
    }

    public function test_the_hours_are_read_in_the_time_zone_of_the_company(): void
    {
        $this->openOffice();
        $subscription = $this->subscriptionOnTier(responseHours: 1, resolutionHours: 6, calendar: 'business');
        $ticket = $this->ticketOf($subscription);
        $this->travelTo(Carbon::parse('2026-03-02 09:30:00', 'UTC'));

        // 16:30 in Jakarta is open: half an hour today, half an hour tomorrow morning.
        $this->assertSame('2026-03-03 01:30:00', TicketService::computeDeadlines($ticket)['response']->format('Y-m-d H:i:s'));

        // 17:30 in Makassar is not: the hour starts at 08:00 tomorrow.
        Company::query()->whereKey($this->admin->company_id)->update(['timezone' => 'Asia/Makassar']);
        CompanyService::resetCache();
        CompanyService::useCompanyForTests((int) $this->admin->company_id);
        $this->assertSame('2026-03-03 01:00:00', TicketService::computeDeadlines($ticket)['response']->format('Y-m-d H:i:s'));
    }

    public function test_creating_a_ticket_stores_the_deadlines_counted_in_service_hours(): void
    {
        Notification::fake();
        $this->openOffice();
        $this->travelToJakarta('2026-03-02 18:30');
        $category = $this->category(slaHours: 8, responseHours: 3);
        $subscription = $this->subscriptionOnTier(responseHours: 2, resolutionHours: 6, calendar: 'business');

        $ticket = TicketService::create([
            'title' => 'Internet mati',
            'source' => 'customer',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
        ], $this->admin->id);

        $this->assertSame('2026-03-03 03:00:00', $ticket->response_deadline->format('Y-m-d H:i:s'), 'stored in UTC: 10:00 Jakarta');
        $this->assertSame('2026-03-03 07:00:00', $ticket->sla_deadline->format('Y-m-d H:i:s'), 'stored in UTC: 14:00 Jakarta');
        $this->assertSame('business', $this->showPage($ticket)['deadline_source']['calendar']);
    }

    public function test_the_preview_answers_with_the_calendar_the_ticket_will_be_counted_in(): void
    {
        $this->openOffice();
        $this->travelToJakarta('2026-03-02 18:30');
        $category = $this->category(slaHours: 8, responseHours: 3);
        $subscription = $this->subscriptionOnTier(responseHours: 2, resolutionHours: 6, calendar: 'business');

        $answer = $this->getJson(route('admin.tickets.sla-preview', ['category_id' => $category->public_id, 'subscription_id' => $subscription->public_id]))
            ->assertOk()->json();

        $this->assertSame('business', $answer['calendar']);
        $this->assertSame('business', $answer['source']['calendar']);
        $this->assertSame('2026-03-03 10:00', $this->jakarta(Carbon::parse($answer['response_due'])));
        $this->assertSame('2026-03-03 14:00', $this->jakarta(Carbon::parse($answer['resolution_due'])));
    }

    public function test_the_tier_form_takes_the_calendar_and_a_tier_without_one_counts_24_hours(): void
    {
        $this->assertTrue(Schema::hasColumn('sla_tiers', 'calendar'));
        $payload = ['name' => 'Jam Kerja', 'response_time_hours' => 2, 'resolution_time_hours' => 8, 'is_active' => true];

        $this->post(route('admin.sla-tiers.store'), $payload)->assertRedirect(route('admin.sla-tiers.index'));
        $this->assertSame('24h', DB::table('sla_tiers')->where('name', 'Jam Kerja')->value('calendar'));

        $this->post(route('admin.sla-tiers.store'), [...$payload, 'name' => 'Jam Layanan', 'calendar' => 'business'])->assertRedirect(route('admin.sla-tiers.index'));
        $tier = SLATier::query()->where('name', 'Jam Layanan')->sole();
        $this->assertSame('business', $tier->calendar);

        $this->put(route('admin.sla-tiers.update', $tier), [...$payload, 'name' => 'Jam Layanan', 'calendar' => '24h'])->assertRedirect(route('admin.sla-tiers.index'));
        $this->assertSame('24h', $tier->fresh()->calendar);

        $this->put(route('admin.sla-tiers.update', $tier), [...$payload, 'name' => 'Jam Layanan', 'calendar' => 'business'])->assertRedirect();
        $this->put(route('admin.sla-tiers.update', $tier), [...$payload, 'name' => 'Jam Layanan'])->assertRedirect();
        $this->assertSame('business', $tier->fresh()->calendar, 'a form that does not send it leaves it as it was');
    }

    public function test_the_tier_form_refuses_a_calendar_that_is_not_one_of_the_two(): void
    {
        $tier = SLATier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Gold']);
        $payload = ['name' => 'Gold', 'response_time_hours' => 2, 'resolution_time_hours' => 8, 'is_active' => true];

        foreach (['weekly', 'BUSINESS', '', '24'] as $bad) {
            $this->post(route('admin.sla-tiers.store'), [...$payload, 'name' => 'Baru', 'calendar' => $bad])->assertSessionHasErrors('calendar');
            $this->put(route('admin.sla-tiers.update', $tier), [...$payload, 'calendar' => $bad])->assertSessionHasErrors('calendar');
        }

        $this->assertSame('24h', $tier->fresh()->calendar);
        $this->assertSame(0, SLATier::query()->where('name', 'Baru')->count());
    }

    public function test_the_tier_pages_carry_the_calendar_and_whether_the_company_set_its_hours(): void
    {
        $tier = SLATier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Jam Layanan', 'calendar' => 'business']);

        $this->get(route('admin.sla-tiers.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('slaTiers.data.0.calendar', 'business'));
        $this->get(route('admin.sla-tiers.edit', $tier))->assertOk()
            ->assertInertia(fn ($page) => $page->where('slaTier.data.calendar', 'business')->where('serviceHoursSet', false));
        $this->get(route('admin.sla-tiers.create'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('serviceHoursSet', false));

        $this->openOffice();

        $this->get(route('admin.sla-tiers.edit', $tier))->assertOk()
            ->assertInertia(fn ($page) => $page->where('serviceHoursSet', true));
        $this->get(route('admin.sla-tiers.create'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('serviceHoursSet', true));
    }

    public function test_the_export_lists_how_each_tier_counts_its_hours(): void
    {
        SLATier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Jam Layanan', 'calendar' => 'business']);
        SLATier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Seharian', 'calendar' => '24h']);

        $csv = $this->get(route('admin.sla-tiers.export', ['format' => 'csv']))->assertOk()->streamedContent();

        $this->assertStringContainsString('Hitungan tenggat', $csv);
        $this->assertMatchesRegularExpression('/Jam Layanan.*Jam layanan/', $csv);
        $this->assertMatchesRegularExpression('/Seharian.*24 jam/', $csv);
    }

    public function test_the_settings_form_stores_the_week_and_a_week_without_an_open_day_is_not_set(): void
    {
        $company = Company::query()->findOrFail($this->admin->company_id);

        $this->saveSettings(['service_hours' => self::OFFICE])->assertSessionHasNoErrors();

        $this->assertEquals(self::OFFICE, Company::query()->findOrFail($company->id)->settings['service_hours'], 'the JSON column keeps the days in its own order');
        $this->assertSame(self::OFFICE, ServiceHours::of(Company::query()->findOrFail($company->id)));

        $this->saveSettings(['service_hours' => array_fill_keys(ServiceHours::DAYS, null)])->assertSessionHasNoErrors();

        $fresh = Company::query()->findOrFail($company->id);
        $this->assertNull($fresh->settings['service_hours'], 'stored as nothing');
        $this->assertNull(ServiceHours::of($fresh));
    }

    public function test_the_settings_form_keeps_what_it_did_not_send(): void
    {
        $this->openOffice();

        $this->saveSettings(['settings' => ['tax_ppn_rate' => 12]])->assertSessionHasNoErrors();

        $settings = Company::query()->findOrFail($this->admin->company_id)->settings;
        $this->assertEquals(self::OFFICE, $settings['service_hours'], 'a save of other settings leaves the hours alone');
        $this->assertEquals(12, $settings['tax_ppn_rate']);
    }

    public function test_the_settings_form_refuses_hours_that_make_no_sense_and_saves_nothing(): void
    {
        $bad = [
            'a closing time before the opening' => [['mon' => ['17:00', '08:00']], 'service_hours.mon.1'],
            'a day that opens and closes together' => [['tue' => ['08:00', '08:00']], 'service_hours.tue.1'],
            'a time without the leading zero' => [['wed' => ['8:00', '17:00']], 'service_hours.wed.0'],
            'a time with seconds' => [['thu' => ['08:00', '17:00:00']], 'service_hours.thu.1'],
            'a time that is not one' => [['fri' => ['pagi', '17:00']], 'service_hours.fri.0'],
            'a day with one time' => [['sat' => ['08:00']], 'service_hours.sat'],
            'a day with three times' => [['sat' => ['08:00', '10:00', '12:00']], 'service_hours.sat'],
            'a day that is not a day' => [['xyz' => ['08:00', '17:00']], 'service_hours'],
            'a closing time that is missing' => [['mon' => ['08:00', null]], 'service_hours.mon.1'],
        ];

        foreach ($bad as $label => [$week, $field]) {
            $this->saveSettings(['service_hours' => $week]);
            $this->assertTrue(session('errors')?->has($field) ?? false, $label);
        }

        $this->assertArrayNotHasKey('service_hours', Company::query()->findOrFail($this->admin->company_id)->settings ?? []);
    }

    public function test_the_hours_cannot_be_smuggled_in_through_the_free_form_settings(): void
    {
        $this->saveSettings(['settings' => ['service_hours' => ['mon' => 'sepanjang hari']]])->assertSessionHasErrors('settings.service_hours');

        $this->assertArrayNotHasKey('service_hours', Company::query()->findOrFail($this->admin->company_id)->settings ?? []);
    }

    public function test_the_settings_page_lists_all_seven_days_closed_until_the_company_sets_them(): void
    {
        $closed = array_fill_keys(ServiceHours::DAYS, null);

        $this->get(route('admin.company.settings.edit'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('serviceHours', $closed)->missing('settings.service_hours'));

        $this->openOffice();

        $this->get(route('admin.company.settings.edit'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('serviceHours', self::OFFICE)->missing('settings.service_hours'));
    }

    /**
     * @param  array<string, mixed>  $body  `service_hours` and/or `settings`
     */
    private function saveSettings(array $body): TestResponse
    {
        $company = Company::query()->findOrFail($this->admin->company_id);

        return $this->put(route('admin.company.settings.update'), [
            'settings' => $body['settings'] ?? ['tax_ppn_rate' => 11],
            ...array_diff_key($body, ['settings' => true]),
            OptimisticLock::FIELD => OptimisticLock::version($company),
        ]);
    }

    private function openOffice(): void
    {
        CompanyService::updateSettings(['service_hours' => self::OFFICE]);
    }

    private function travelToJakarta(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment, 'Asia/Jakarta'));
    }

    private function jakarta(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i');
    }

    /** @return array<string, mixed> */
    private function showPage(Ticket $ticket): array
    {
        return $this->get(route('admin.tickets.show', $ticket))->assertOk()->viewData('page')['props']['ticket']['data'];
    }

    private function ticketOf(ServiceSubscription $subscription, string $priority = 'medium'): Ticket
    {
        $category = $this->category(slaHours: 24, responseHours: 12);

        return new Ticket(['category_id' => $category->id, 'subscription_id' => $subscription->id, 'priority' => $priority]);
    }

    private function category(int $slaHours, int $responseHours): TicketCategory
    {
        return TicketCategory::create([
            'company_id' => $this->admin->company_id,
            'name' => 'Gangguan',
            'code' => 'CAT-'.uniqid(),
            'default_sla_hours' => $slaHours,
            'default_response_hours' => $responseHours,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
    }

    /** A subscription whose package sits on a fresh SLA tier of the company. */
    private function subscriptionOnTier(int $responseHours, int $resolutionHours, string $calendar, string $tierName = 'Gold'): ServiceSubscription
    {
        $companyId = (int) $this->admin->company_id;
        $tier = SLATier::factory()->create([
            'company_id' => $companyId,
            'name' => $tierName,
            'response_time_hours' => $responseHours,
            'resolution_time_hours' => $resolutionHours,
            'calendar' => $calendar,
            'is_active' => true,
        ]);
        $package = ServicePackageFactory::new()->create(['company_id' => $companyId, 'sla_tier_id' => $tier->id]);
        $customer = Customer::factory()->create(['company_id' => $companyId]);
        $address = CustomerAddress::factory()->create(['company_id' => $companyId, 'customer_id' => $customer->id]);

        return ServiceSubscription::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'status' => 'active',
        ]);
    }
}
