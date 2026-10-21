<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\SLATier;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * The seam is TicketService::computeDeadlines(): which target a ticket takes (its subscription's SLA tier,
 * else its category) and how priority urgent shortens it. Expected moments are worked out by hand from a
 * frozen clock of 2026-03-02 09:00:00.
 */
class TicketDeadlinesTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-03-02 09:00:00'));
    }

    public function test_a_ticket_without_subscription_takes_the_targets_of_its_category(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 8, responseHours: 3);

        $deadlines = TicketService::computeDeadlines(new Ticket(['category_id' => $category->id, 'priority' => 'medium']));

        $this->assertSame('2026-03-02 17:00:00', $deadlines['resolution']->toDateTimeString());
        $this->assertSame('2026-03-02 12:00:00', $deadlines['response']->toDateTimeString());
    }

    public function test_priority_urgent_halves_both_category_targets_rounding_up(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 9, responseHours: 5);

        $deadlines = TicketService::computeDeadlines(new Ticket(['category_id' => $category->id, 'priority' => 'urgent']));

        $this->assertSame('2026-03-02 14:00:00', $deadlines['resolution']->toDateTimeString());
        $this->assertSame('2026-03-02 12:00:00', $deadlines['response']->toDateTimeString());
    }

    public function test_a_ticket_without_priority_follows_the_default_priority_of_its_category(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 4, responseHours: 2, defaultPriority: 'urgent');

        $deadlines = TicketService::computeDeadlines(new Ticket(['category_id' => $category->id]));

        $this->assertSame('2026-03-02 11:00:00', $deadlines['resolution']->toDateTimeString());
        $this->assertSame('2026-03-02 10:00:00', $deadlines['response']->toDateTimeString());
    }

    public function test_a_category_without_response_hours_gives_no_response_deadline(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 8, responseHours: 3);
        $category->default_response_hours = null;
        $ticket = (new Ticket(['priority' => 'medium']))->setRelation('category', $category)->setRelation('subscription', null);

        $deadlines = TicketService::computeDeadlines($ticket);

        $this->assertNull($deadlines['response']);
        $this->assertSame('2026-03-02 17:00:00', $deadlines['resolution']->toDateTimeString());
    }

    public function test_a_ticket_of_a_subscription_takes_the_targets_of_the_tier_of_its_package(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 8, responseHours: 3);
        $subscription = $this->subscriptionOnTier($user->company_id, responseHours: 2, resolutionHours: 6);

        $deadlines = TicketService::computeDeadlines(
            new Ticket(['category_id' => $category->id, 'subscription_id' => $subscription->id, 'priority' => 'medium'])
        );

        $this->assertSame('2026-03-02 15:00:00', $deadlines['resolution']->toDateTimeString());
        $this->assertSame('2026-03-02 11:00:00', $deadlines['response']->toDateTimeString());
    }

    public function test_priority_urgent_halves_both_tier_targets_rounding_up(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 12, responseHours: 6);
        $subscription = $this->subscriptionOnTier($user->company_id, responseHours: 3, resolutionHours: 7);

        $deadlines = TicketService::computeDeadlines(
            new Ticket(['category_id' => $category->id, 'subscription_id' => $subscription->id, 'priority' => 'urgent'])
        );

        $this->assertSame('2026-03-02 13:00:00', $deadlines['resolution']->toDateTimeString());
        $this->assertSame('2026-03-02 11:00:00', $deadlines['response']->toDateTimeString());
    }

    public function test_a_deactivated_tier_still_sets_the_targets_of_the_subscriptions_sold_under_it(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 8, responseHours: 3);
        $subscription = $this->subscriptionOnTier($user->company_id, responseHours: 2, resolutionHours: 6, active: false);

        $deadlines = TicketService::computeDeadlines(
            new Ticket(['category_id' => $category->id, 'subscription_id' => $subscription->id, 'priority' => 'medium'])
        );

        $this->assertSame('2026-03-02 15:00:00', $deadlines['resolution']->toDateTimeString());
        $this->assertSame('2026-03-02 11:00:00', $deadlines['response']->toDateTimeString());
    }

    public function test_the_subscription_of_another_company_never_lends_its_tier(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 8, responseHours: 3);
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = CompanyService::runAs((int) $other->id, fn (): ServiceSubscription => $this->subscriptionOnTier($other->id, responseHours: 1, resolutionHours: 2));

        $deadlines = TicketService::computeDeadlines(
            new Ticket(['category_id' => $category->id, 'subscription_id' => $foreign->id, 'priority' => 'medium'])
        );

        $this->assertSame('2026-03-02 17:00:00', $deadlines['resolution']->toDateTimeString());
        $this->assertSame('2026-03-02 12:00:00', $deadlines['response']->toDateTimeString());
    }

    public function test_a_tier_of_another_company_on_an_own_package_never_applies(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 8, responseHours: 3);
        $other = Company::factory()->create(['is_active' => true]);
        $foreignTier = CompanyService::runAs((int) $other->id, fn (): SLATier => SLATier::factory()->create([
            'company_id' => $other->id,
            'response_time_hours' => 1,
            'resolution_time_hours' => 2,
        ]));
        $subscription = $this->subscriptionOnTier($user->company_id, responseHours: 5, resolutionHours: 10);
        // A version is immutable through the model, so the foreign tier is planted on the row the subscription holds.
        DB::table('service_package_versions')->where('id', $subscription->service_package_version_id)->update(['sla_tier_id' => $foreignTier->id]);

        $deadlines = TicketService::computeDeadlines(
            new Ticket(['category_id' => $category->id, 'subscription_id' => $subscription->id, 'priority' => 'medium'])
        );

        $this->assertSame('2026-03-02 17:00:00', $deadlines['resolution']->toDateTimeString());
        $this->assertSame('2026-03-02 12:00:00', $deadlines['response']->toDateTimeString());
    }

    public function test_the_source_of_the_targets_names_the_tier_or_the_category(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 8, responseHours: 3, name: 'Gangguan');
        $gold = $this->subscriptionOnTier($user->company_id, responseHours: 2, resolutionHours: 6, tierName: 'Gold');
        $retired = $this->subscriptionOnTier($user->company_id, responseHours: 2, resolutionHours: 6, active: false, tierName: 'Lama');

        $names = static fn (Ticket $ticket): array => Arr::only(TicketService::deadlineSource($ticket), ['type', 'name']);

        $this->assertSame(['type' => 'tier', 'name' => 'Gold'], $names(new Ticket(['category_id' => $category->id, 'subscription_id' => $gold->id])));
        $this->assertSame(['type' => 'tier', 'name' => 'Lama'], $names(new Ticket(['category_id' => $category->id, 'subscription_id' => $retired->id])));
        $this->assertSame(['type' => 'category', 'name' => 'Gangguan'], $names(new Ticket(['category_id' => $category->id])));
    }

    public function test_deadline_source_names_base_hours_and_urgent_halving(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 9, responseHours: 5, name: 'Gangguan');
        $gold = $this->subscriptionOnTier($user->company_id, responseHours: 3, resolutionHours: 7, tierName: 'Gold');

        $this->assertSame(
            ['type' => 'category', 'name' => 'Gangguan', 'response_hours' => 5, 'resolution_hours' => 9, 'urgent_halved' => false],
            TicketService::deadlineSource(new Ticket(['category_id' => $category->id, 'priority' => 'medium'])),
        );
        $this->assertSame(
            ['type' => 'category', 'name' => 'Gangguan', 'response_hours' => 5, 'resolution_hours' => 9, 'urgent_halved' => true],
            TicketService::deadlineSource(new Ticket(['category_id' => $category->id, 'priority' => 'urgent'])),
            'the hours stay the configured ones: the page shows them divided',
        );
        $this->assertSame(
            ['type' => 'tier', 'name' => 'Gold', 'response_hours' => 3, 'resolution_hours' => 7, 'urgent_halved' => true],
            TicketService::deadlineSource(new Ticket(['category_id' => $category->id, 'subscription_id' => $gold->id, 'priority' => 'urgent'])),
        );

        $urgentByDefault = $this->category($user, slaHours: 4, responseHours: 2, defaultPriority: 'urgent', name: 'Mendesak');
        $this->assertTrue(
            TicketService::deadlineSource(new Ticket(['category_id' => $urgentByDefault->id]))['urgent_halved'],
            'a ticket without a priority follows the default of its category',
        );

        $category->default_response_hours = null;
        $withoutTarget = (new Ticket(['priority' => 'medium']))->setRelation('category', $category)->setRelation('subscription', null);
        $this->assertNull(TicketService::deadlineSource($withoutTarget)['response_hours']);
    }

    public function test_show_hides_customer_address_without_customer_view(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $category = $this->category($admin, slaHours: 8, responseHours: 3);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'phone' => '081234567890']);
        CustomerAddress::factory()->create(['company_id' => $admin->company_id, 'customer_id' => $customer->id, 'label' => 'Kantor', 'address' => 'Jl. Mawar 5', 'is_primary' => true]);
        CustomerAddress::factory()->create(['company_id' => $admin->company_id, 'customer_id' => $customer->id, 'label' => 'Rumah', 'address' => 'Jl. Melati 9', 'is_installation_point' => true]);
        $ticket = Ticket::query()->create([
            'company_id' => $admin->company_id, 'code' => 'TKT-ADDR', 'title' => 'Internet mati', 'source' => 'customer', 'category_id' => $category->id,
            'status' => 'open', 'priority' => 'medium', 'customer_id' => $customer->id, 'created_by' => $admin->id, 'sla_deadline' => now()->addDay(),
        ]);
        $reader = User::factory()->create(['company_id' => $admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($reader, ['app.web', 'ticket.view']);

        $dispatcher = $this->get(route('admin.tickets.show', $ticket))->assertOk()->viewData('page')['props']['ticket']['data']['customer'];
        $this->assertSame('081234567890', $dispatcher['phone']);
        $this->assertSame(['Jl. Melati 9'], array_column($dispatcher['addresses'], 'address'), 'one address, the installation point before the primary one');

        $limited = $this->actingAs($reader)->get(route('admin.tickets.show', $ticket))->assertOk()->viewData('page')['props']['ticket']['data']['customer'];
        $this->assertSame('081234567890', $limited['phone'], 'the phone number was always on the page');
        $this->assertArrayNotHasKey('addresses', $limited);
    }

    public function test_creating_a_ticket_stores_both_deadlines_and_loads_the_tier_once(): void
    {
        Notification::fake();
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 8, responseHours: 3);
        $subscription = $this->subscriptionOnTier($user->company_id, responseHours: 2, resolutionHours: 6);

        DB::enableQueryLog();
        $ticket = TicketService::create([
            'title' => 'Internet mati',
            'source' => 'customer',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
        ], $user->id);
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertSame('2026-03-02 15:00:00', $ticket->sla_deadline->toDateTimeString());
        $this->assertSame('2026-03-02 11:00:00', $ticket->response_deadline->toDateTimeString());
        $this->assertSame(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from `sla_tiers`'))->count());
    }

    public function test_the_ticket_page_shows_both_deadlines_their_source_and_the_response_state(): void
    {
        Notification::fake();
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $category = $this->category($user, slaHours: 8, responseHours: 3);
        $subscription = $this->subscriptionOnTier($user->company_id, responseHours: 2, resolutionHours: 6);
        $ticket = TicketService::create([
            'title' => 'Internet mati',
            'source' => 'customer',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
        ], $user->id);

        $waiting = $this->get(route('admin.tickets.show', $ticket))->assertOk()->viewData('page')['props']['ticket']['data'];
        $this->travelTo(Carbon::parse('2026-03-02 11:00:01'));
        $late = $this->get(route('admin.tickets.show', $ticket))->assertOk()->viewData('page')['props']['ticket']['data'];

        $this->assertNotNull($waiting['response_deadline']);
        $this->assertSame(
            ['type' => 'tier', 'name' => 'Gold', 'response_hours' => 2, 'resolution_hours' => 6, 'urgent_halved' => false],
            $waiting['deadline_source'],
        );
        $this->assertFalse($waiting['is_response_breached']);
        $this->assertTrue($late['is_response_breached']);
    }

    #[DataProvider('responseStates')]
    public function test_a_ticket_is_response_breached_when_answered_late_or_unanswered_past_the_target(?string $deadline, ?string $respondedAt, string $now, bool $breached): void
    {
        $this->travelTo(Carbon::parse($now));

        $ticket = new Ticket(['response_deadline' => $deadline, 'first_response_at' => $respondedAt]);

        $this->assertSame($breached, $ticket->is_response_breached);
    }

    /** @return array<string, array{?string, ?string, string, bool}> deadline, first response, now, breached */
    public static function responseStates(): array
    {
        return [
            'no target' => [null, null, '2026-03-02 15:00:00', false],
            'target ahead, no response yet' => ['2026-03-02 11:00:00', null, '2026-03-02 10:59:59', false],
            'target passed, no response' => ['2026-03-02 11:00:00', null, '2026-03-02 11:00:01', true],
            'answered in time' => ['2026-03-02 11:00:00', '2026-03-02 10:59:00', '2026-03-02 15:00:00', false],
            'answered exactly on the target' => ['2026-03-02 11:00:00', '2026-03-02 11:00:00', '2026-03-02 15:00:00', false],
            'answered late' => ['2026-03-02 11:00:00', '2026-03-02 11:00:01', '2026-03-02 15:00:00', true],
        ];
    }

    private function category(User $user, int $slaHours, int $responseHours, string $defaultPriority = 'medium', string $name = 'Gangguan'): TicketCategory
    {
        return TicketCategory::create([
            'company_id' => $user->company_id,
            'name' => $name,
            'code' => 'CAT-'.uniqid(),
            'default_sla_hours' => $slaHours,
            'default_response_hours' => $responseHours,
            'default_priority' => $defaultPriority,
            'is_active' => true,
        ]);
    }

    /** A subscription whose package sits on a fresh SLA tier of the company. */
    private function subscriptionOnTier(int $companyId, int $responseHours, int $resolutionHours, bool $active = true, string $tierName = 'Gold'): ServiceSubscription
    {
        $tier = SLATier::factory()->create([
            'company_id' => $companyId,
            'name' => $tierName,
            'response_time_hours' => $responseHours,
            'resolution_time_hours' => $resolutionHours,
            'is_active' => $active,
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
