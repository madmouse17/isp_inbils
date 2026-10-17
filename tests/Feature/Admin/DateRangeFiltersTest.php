<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * date_from/date_to on Invoices, the Dispatch queue and My Work: an inverted range is rejected, and
 * either end alone filters ("from" = that day on, "to" = up to and including that day).
 */
class DateRangeFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        foreach (['ticket.assign', 'ticket.start'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->user->assignRole('admin');
        $this->user->givePermissionTo(['ticket.assign', 'ticket.start']);
        EmployeeProfile::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'status' => 'active']);
    }

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            'invoices' => ['admin.invoices.index'],
            'dispatch queue' => ['admin.dispatch.index'],
            'my work' => ['admin.my-work.index'],
        ];
    }

    #[DataProvider('pages')]
    public function test_an_end_before_the_start_is_rejected(string $route): void
    {
        $this->actingAs($this->user)
            ->getJson(route($route, ['date_from' => '2026-03-20', 'date_to' => '2026-03-10']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    #[DataProvider('pages')]
    public function test_a_malformed_date_is_rejected(string $route): void
    {
        $this->actingAs($this->user)
            ->getJson(route($route, ['date_from' => '20-03-2026', 'date_to' => 'tomorrow']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    #[DataProvider('pages')]
    public function test_a_single_date_and_a_same_day_range_pass_validation(string $route): void
    {
        $this->actingAs($this->user)->get(route($route, ['date_from' => '2026-03-20']))->assertOk();
        $this->actingAs($this->user)->get(route($route, ['date_to' => '2026-03-20']))->assertOk();
        $this->actingAs($this->user)->get(route($route, ['date_from' => '2026-03-20', 'date_to' => '2026-03-20']))->assertOk();
    }

    public function test_invoices_filter_by_one_end_only(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $ids = [];
        foreach (['2026-03-10', '2026-03-20', '2026-03-30'] as $issueDate) {
            $ids[$issueDate] = Invoice::factory()->create([
                'company_id' => $this->company->id,
                'customer_id' => $customer->id,
                'issue_date' => $issueDate,
                'due_date' => '2026-04-30',
            ])->public_id;
        }

        $this->assertInvoiceIds([$ids['2026-03-20'], $ids['2026-03-30']], ['date_from' => '2026-03-20']);
        $this->assertInvoiceIds([$ids['2026-03-10'], $ids['2026-03-20']], ['date_to' => '2026-03-20']);
        $this->assertInvoiceIds([$ids['2026-03-20']], ['date_from' => '2026-03-20', 'date_to' => '2026-03-20']);
    }

    public function test_the_dispatch_queue_filters_by_one_end_only(): void
    {
        $ids = $this->ticketsCreatedOn(['2026-03-10', '2026-03-20', '2026-03-30'], assign: false);

        $this->assertItemIds('admin.dispatch.index', [$ids[1], $ids[2]], ['date_from' => '2026-03-20']);
        $this->assertItemIds('admin.dispatch.index', [$ids[0], $ids[1]], ['date_to' => '2026-03-20']);
        $this->assertItemIds('admin.dispatch.index', [$ids[1]], ['date_from' => '2026-03-20', 'date_to' => '2026-03-20']);
    }

    public function test_my_work_filters_by_one_end_only(): void
    {
        $ids = $this->ticketsCreatedOn(['2026-03-10', '2026-03-20', '2026-03-30'], assign: true);

        $this->assertItemIds('admin.my-work.index', [$ids[1], $ids[2]], ['date_from' => '2026-03-20']);
        $this->assertItemIds('admin.my-work.index', [$ids[0], $ids[1]], ['date_to' => '2026-03-20']);
        $this->assertItemIds('admin.my-work.index', [$ids[1]], ['date_from' => '2026-03-20', 'date_to' => '2026-03-20']);
    }

    /**
     * @param  list<string>  $expected  public ids
     * @param  array<string, string>  $query
     */
    private function assertInvoiceIds(array $expected, array $query): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.invoices.index', $query))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Invoices/Index')
                ->where('invoices.data', fn ($rows) => $this->sortedIds($rows) === $this->sorted($expected)));
    }

    /**
     * @param  list<string>  $expected  public ids
     * @param  array<string, string>  $query
     */
    private function assertItemIds(string $route, array $expected, array $query): void
    {
        $this->actingAs($this->user)
            ->get(route($route, $query))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data', fn ($rows) => $this->sortedIds($rows) === $this->sorted($expected)));
    }

    /** @return list<string> */
    private function sortedIds(mixed $rows): array
    {
        return $this->sorted(collect($rows)->pluck('id')->all());
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        return collect($ids)->sort()->values()->all();
    }

    /**
     * Open tickets created on the given days, oldest first; assigned to the user when $assign is set.
     *
     * @param  list<string>  $days
     * @return list<string> public ids
     */
    private function ticketsCreatedOn(array $days, bool $assign): array
    {
        CompanyService::useCompanyForTests($this->company->id);
        $category = TicketCategory::create([
            'company_id' => $this->company->id,
            'name' => 'General Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        $ids = [];
        foreach ($days as $day) {
            $ticket = TicketService::create([
                'title' => "Ticket {$day}",
                'description' => 'Cannot connect',
                'source' => 'internal',
                'category_id' => $category->id,
                'priority' => 'medium',
            ], $this->user->id);

            if ($assign) {
                Ticket::query()->whereKey($ticket->id)->update(['assigned_to' => $this->user->id, 'status' => 'assigned']);
            }
            DB::table('tickets')->where('id', $ticket->id)->update(['created_at' => "{$day} 12:00:00"]);
            $ids[] = $ticket->public_id;
        }

        return $ids;
    }
}
