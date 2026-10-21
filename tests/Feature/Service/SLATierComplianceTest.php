<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\SLATier;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;

class SLATierComplianceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($this->admin, 'admin');
    }

    public function test_index_computes_response_and_resolution_rates_per_tier_over_thirty_days_and_per_company(): void
    {
        $companyId = $this->admin->company_id;
        $gold = SLATier::factory()->create(['company_id' => $companyId, 'name' => 'Gold']);
        $silver = SLATier::factory()->create(['company_id' => $companyId, 'name' => 'Silver']);
        $idle = SLATier::factory()->create(['company_id' => $companyId, 'name' => 'Idle']);
        $foreignGold = SLATier::factory()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Gold']);
        $now = now();

        $goldSub = $this->subscription($gold);
        // On time (resolution + response), breached resolution, then two that must not move the rates.
        $this->ticket($goldSub, $now->copy()->subDays(2), resolvedAt: $now->copy()->subDay(), deadline: $now, respondedAt: $now->copy()->subDays(2)->addHour(), responseDeadline: $now->copy()->subDays(2)->addHours(2));
        $this->ticket($goldSub, $now->copy()->subDays(3), resolvedAt: $now->copy()->subDay(), deadline: $now->copy()->subDays(2), respondedAt: $now->copy()->subDays(3)->addHours(5), responseDeadline: $now->copy()->subDays(3)->addHours(2));
        // Old ticket without response target and still open: counted in tickets_30d only.
        $this->ticket($goldSub, $now->copy()->subDay(), deadline: $now->copy()->addDay());
        // Older than 30 days: outside the window.
        $this->ticket($goldSub, $now->copy()->subDays(45), resolvedAt: $now->copy()->subDays(40), deadline: $now->copy()->subDays(44));
        // Same tier, other company's tickets must not leak into the figures.
        $this->ticket($this->subscription($foreignGold), $now->copy()->subDay(), resolvedAt: $now, deadline: $now->copy()->subDays(5));

        $silverSub = $this->subscription($silver);
        $this->ticket($silverSub, $now->copy()->subDays(4), resolvedAt: $now->copy()->subDays(3), deadline: $now->copy()->subDays(2));

        $response = $this->actingAs($this->admin)->get(route('admin.sla-tiers.index', ['per_page' => 25]))->assertOk();

        $rows = collect($response->viewData('page')['props']['slaTiers']['data'])->keyBy('name');
        $this->assertSame(3, $rows['Gold']['tickets_30d']);
        $this->assertSame(50.0, (float) $rows['Gold']['resolution_on_time_rate_30d']);
        $this->assertSame(50.0, (float) $rows['Gold']['response_on_time_rate_30d']);
        $this->assertSame(1, $rows['Silver']['tickets_30d']);
        $this->assertSame(100.0, (float) $rows['Silver']['resolution_on_time_rate_30d']);
        $this->assertNull($rows['Silver']['response_on_time_rate_30d']);
        $this->assertSame(0, $rows['Idle']['tickets_30d']);
        $this->assertNull($rows['Idle']['resolution_on_time_rate_30d']);
        $this->assertNull($rows['Idle']['response_on_time_rate_30d']);
    }

    public function test_tier_payload_and_export_drop_uptime_and_credit(): void
    {
        SLATier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Gold']);

        $this->actingAs($this->admin)->get(route('admin.sla-tiers.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('slaTiers.data.0.uptime_pct')
            ->missing('slaTiers.data.0.credit_pct')
            ->has('slaTiers.data.0.tickets_30d'));

        $csv = $this->get(route('admin.sla-tiers.export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Uptime', $csv);
        $this->assertStringNotContainsString('Credit', $csv);
        $this->assertFalse(Schema::hasColumn('sla_tiers', 'uptime_pct'));
        $this->assertFalse(Schema::hasColumn('sla_tiers', 'credit_pct'));
    }

    public function test_tier_is_created_without_uptime_or_credit(): void
    {
        $this->actingAs($this->admin)->post(route('admin.sla-tiers.store'), [
            'name' => 'Platinum', 'response_time_hours' => 2, 'resolution_time_hours' => 8, 'is_active' => true,
        ])->assertRedirect(route('admin.sla-tiers.index'));

        $this->assertDatabaseHas('sla_tiers', ['company_id' => $this->admin->company_id, 'name' => 'Platinum', 'response_time_hours' => 2]);
    }

    public function test_tier_hours_are_bounded_and_response_not_longer_than_resolution(): void
    {
        $tier = SLATier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Gold']);
        $payload = fn (array $override): array => [...['name' => 'Platinum', 'response_time_hours' => 2, 'resolution_time_hours' => 8, 'is_active' => true], ...$override];

        foreach ([
            [['response_time_hours' => 0], 'response_time_hours'],
            [['resolution_time_hours' => 721], 'resolution_time_hours'],
            [['response_time_hours' => 721, 'resolution_time_hours' => 720], 'response_time_hours'],
            [['response_time_hours' => 9, 'resolution_time_hours' => 8], 'response_time_hours'],
        ] as [$override, $field]) {
            $this->actingAs($this->admin)->post(route('admin.sla-tiers.store'), $payload($override))->assertSessionHasErrors($field);
            $this->actingAs($this->admin)->put(route('admin.sla-tiers.update', $tier), $payload([...$override, 'name' => 'Gold']))->assertSessionHasErrors($field);
        }

        $this->actingAs($this->admin)->post(route('admin.sla-tiers.store'), $payload(['response_time_hours' => 9, 'resolution_time_hours' => 8]))
            ->assertSessionHasErrors(['response_time_hours' => 'Target respons tidak boleh lebih lama dari target penyelesaian.']);

        $this->actingAs($this->admin)->put(route('admin.sla-tiers.update', $tier), $payload(['name' => 'Gold', 'response_time_hours' => 720, 'resolution_time_hours' => 720]))->assertSessionHasNoErrors();
        $this->assertSame(720, $tier->fresh()->resolution_time_hours);
    }

    private function subscription(SLATier $tier): ServiceSubscription
    {
        $companyId = $tier->company_id;
        $customer = Customer::factory()->create(['company_id' => $companyId]);

        return ServiceSubscription::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackage::factory()->create(['company_id' => $companyId, 'sla_tier_id' => $tier->id])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['customer_id' => $customer->id])->id,
        ]);
    }

    private function ticket(
        ServiceSubscription $subscription,
        \DateTimeInterface $createdAt,
        ?\DateTimeInterface $resolvedAt = null,
        ?\DateTimeInterface $deadline = null,
        ?\DateTimeInterface $respondedAt = null,
        ?\DateTimeInterface $responseDeadline = null,
    ): Ticket {
        $companyId = $subscription->company_id;
        $category = TicketCategory::withoutCompany()->where('company_id', $companyId)->first()
            ?? TicketCategory::query()->forceCreate([
                'company_id' => $companyId, 'name' => 'Gangguan', 'code' => 'gangguan', 'default_sla_hours' => 8,
                'default_response_hours' => 4, 'default_priority' => 'medium', 'is_active' => true,
            ]);

        return Ticket::query()->forceCreate([
            'company_id' => $companyId,
            'code' => 'TCK-'.$companyId.'-'.++$this->sequence,
            'title' => 'Compliance fixture '.$this->sequence,
            'source' => 'internal',
            'category_id' => $category->id,
            'subscription_id' => $subscription->id,
            'status' => $resolvedAt === null ? 'open' : 'resolved',
            'priority' => 'medium',
            'created_by' => User::factory()->create(['company_id' => $companyId])->id,
            'created_at' => $createdAt,
            'first_response_at' => $respondedAt,
            'resolved_at' => $resolvedAt,
            'sla_deadline' => $deadline,
            'response_deadline' => $responseDeadline,
        ]);
    }
}
