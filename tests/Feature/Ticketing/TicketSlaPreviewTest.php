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
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\SLATier;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * The create form asks what a ticket would be held to before anything is saved. The answer is the deadlines and the source
 * the ticket gets when it is made, for the category, the subscription and the priority on the form.
 */
class TicketSlaPreviewTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-03-02 09:00:00'));
        $this->admin = $this->createCompanyUser();
    }

    public function test_a_category_gives_its_hours_and_names_itself(): void
    {
        $category = $this->category(slaHours: 8, responseHours: 3, name: 'Gangguan');

        $answer = $this->preview(['category_id' => $category->public_id])->assertOk()->json();

        $this->assertSame('2026-03-02 12:00:00', $this->utc($answer['response_due']));
        $this->assertSame('2026-03-02 17:00:00', $this->utc($answer['resolution_due']));
        $this->assertSame(['type' => 'category', 'name' => 'Gangguan', 'response_hours' => 3, 'resolution_hours' => 8, 'urgent_halved' => false], $answer['source']);
        $this->assertSame('24h', $answer['calendar']);
        $this->assertSame(['response_due', 'resolution_due', 'source', 'calendar'], array_keys($answer));
    }

    public function test_the_subscription_of_a_tier_brings_the_tier_targets(): void
    {
        $category = $this->category(slaHours: 8, responseHours: 3);
        $subscription = $this->subscriptionOnTier(responseHours: 2, resolutionHours: 6, tierName: 'Gold');

        $answer = $this->preview(['category_id' => $category->public_id, 'subscription_id' => $subscription->public_id])->assertOk()->json();

        $this->assertSame('2026-03-02 11:00:00', $this->utc($answer['response_due']));
        $this->assertSame('2026-03-02 15:00:00', $this->utc($answer['resolution_due']));
        $this->assertSame(['type' => 'tier', 'name' => 'Gold', 'response_hours' => 2, 'resolution_hours' => 6, 'urgent_halved' => false], $answer['source']);
    }

    public function test_priority_urgent_halves_both_targets_rounding_up(): void
    {
        $category = $this->category(slaHours: 9, responseHours: 5);

        $answer = $this->preview(['category_id' => $category->public_id, 'priority' => 'urgent'])->assertOk()->json();

        $this->assertSame('2026-03-02 12:00:00', $this->utc($answer['response_due']));
        $this->assertSame('2026-03-02 14:00:00', $this->utc($answer['resolution_due']));
        $this->assertTrue($answer['source']['urgent_halved']);
    }

    public function test_without_a_priority_the_default_of_the_category_counts(): void
    {
        $category = $this->category(slaHours: 4, responseHours: 2, defaultPriority: 'urgent');

        $answer = $this->preview(['category_id' => $category->public_id])->assertOk()->json();

        $this->assertSame('2026-03-02 11:00:00', $this->utc($answer['resolution_due']));
        $this->assertTrue($answer['source']['urgent_halved']);
    }

    public function test_the_preview_is_what_the_ticket_then_gets_and_it_saves_nothing(): void
    {
        Notification::fake();
        $category = $this->category(slaHours: 8, responseHours: 3);
        $subscription = $this->subscriptionOnTier(responseHours: 2, resolutionHours: 6);

        $answer = $this->preview(['category_id' => $category->public_id, 'subscription_id' => $subscription->public_id, 'priority' => 'high'])->assertOk()->json();
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'ticket')->count());

        $this->post(route('admin.tickets.store'), [
            'title' => 'Internet mati', 'source' => 'customer', 'category_id' => $category->public_id, 'priority' => 'high',
            'customer_id' => Customer::query()->find($subscription->customer_id)->public_id, 'subscription_id' => $subscription->public_id,
        ])->assertRedirect();

        $ticket = Ticket::query()->sole();
        $this->assertSame($this->utc($answer['response_due']), $ticket->response_deadline->toDateTimeString());
        $this->assertSame($this->utc($answer['resolution_due']), $ticket->sla_deadline->toDateTimeString());
    }

    public function test_it_asks_for_a_category_subscription_and_priority_the_company_knows(): void
    {
        $category = $this->category(slaHours: 8, responseHours: 3);
        $other = Company::factory()->create(['is_active' => true]);
        $foreignCategory = CompanyService::runAs((int) $other->id, fn (): TicketCategory => TicketCategory::query()->create([
            'company_id' => $other->id, 'name' => 'Asing', 'code' => 'AS-1', 'default_sla_hours' => 1, 'default_response_hours' => 1, 'default_priority' => 'medium', 'is_active' => true,
        ]));
        $foreignSubscription = CompanyService::runAs((int) $other->id, fn (): ServiceSubscription => $this->subscriptionOnTier(1, 2, companyId: (int) $other->id));

        foreach ([
            'no category' => [[], 'category_id'],
            'a foreign category' => [['category_id' => $foreignCategory->public_id], 'category_id'],
            'a number, not an id' => [['category_id' => (string) $category->id], 'category_id'],
            'a foreign subscription' => [['category_id' => $category->public_id, 'subscription_id' => $foreignSubscription->public_id], 'subscription_id'],
            'an unknown priority' => [['category_id' => $category->public_id, 'priority' => 'asap'], 'priority'],
        ] as $why => [$query, $field]) {
            $this->preview($query)->assertUnprocessable()->assertJsonValidationErrors($field, responseKey: 'errors');
            $this->assertTrue(true, $why);
        }
    }

    public function test_it_needs_the_permission_to_create_tickets(): void
    {
        $category = $this->category(slaHours: 8, responseHours: 3);
        $reader = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($reader, ['app.web', 'ticket.view']);

        $this->actingAs($reader)->getJson(route('admin.tickets.sla-preview', ['category_id' => $category->public_id]))->assertForbidden();
    }

    public function test_a_customer_account_may_only_name_its_own_subscription(): void
    {
        $category = $this->category(slaHours: 8, responseHours: 3);
        $portal = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($portal, 'customer');
        $own = Customer::factory()->create(['company_id' => $this->admin->company_id, 'user_id' => $portal->id]);
        $ownSubscription = ServiceSubscription::factory()->create(['company_id' => $this->admin->company_id, 'customer_id' => $own->id, 'status' => 'active']);
        $someoneElses = $this->subscriptionOnTier(responseHours: 1, resolutionHours: 2);

        $this->actingAs($portal)->getJson(route('admin.tickets.sla-preview', ['category_id' => $category->public_id, 'subscription_id' => $ownSubscription->public_id]))->assertOk();
        $this->actingAs($portal)->getJson(route('admin.tickets.sla-preview', ['category_id' => $category->public_id, 'subscription_id' => $someoneElses->public_id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription_id');
    }

    /** @param array<string, mixed> $query */
    private function preview(array $query): TestResponse
    {
        return $this->actingAs($this->admin)->getJson(route('admin.tickets.sla-preview', $query));
    }

    private function utc(?string $moment): string
    {
        return Carbon::parse((string) $moment)->utc()->toDateTimeString();
    }

    private function category(int $slaHours, int $responseHours, string $defaultPriority = 'medium', string $name = 'Gangguan'): TicketCategory
    {
        return TicketCategory::query()->create([
            'company_id' => $this->admin->company_id, 'name' => $name, 'code' => 'CAT-'.uniqid(), 'default_sla_hours' => $slaHours,
            'default_response_hours' => $responseHours, 'default_priority' => $defaultPriority, 'is_active' => true,
        ]);
    }

    /** A subscription whose package sits on a fresh SLA tier of the company. */
    private function subscriptionOnTier(int $responseHours, int $resolutionHours, string $tierName = 'Gold', ?int $companyId = null): ServiceSubscription
    {
        $companyId ??= (int) $this->admin->company_id;
        $tier = SLATier::factory()->create(['company_id' => $companyId, 'name' => $tierName, 'response_time_hours' => $responseHours, 'resolution_time_hours' => $resolutionHours, 'is_active' => true]);
        $package = ServicePackageFactory::new()->create(['company_id' => $companyId, 'sla_tier_id' => $tier->id]);
        $customer = Customer::factory()->create(['company_id' => $companyId]);
        $address = CustomerAddress::factory()->create(['company_id' => $companyId, 'customer_id' => $customer->id]);

        return ServiceSubscription::factory()->create([
            'company_id' => $companyId, 'customer_id' => $customer->id, 'service_package_id' => $package->id, 'installation_address_id' => $address->id, 'status' => 'active',
        ]);
    }
}
