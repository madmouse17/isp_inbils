<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\User;
use App\Queries\Admin\MyWorkQuery;
use App\Queries\Admin\MyWorkUnifiedQuery;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

class MyWorkTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        foreach (['ticket.assign', 'spk.assign', 'ticket.start', 'ticket.resolve', 'ticket.close', 'spk.start', 'spk.submit'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    public function test_unauthenticated_user_cannot_access_my_work(): void
    {
        $this->get(route('admin.my-work.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_operational_permission_is_forbidden(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($user, 'customer');

        $this->actingAs($user)
            ->get(route('admin.my-work.index'))
            ->assertForbidden();
    }

    public function test_technician_with_ticket_permission_only_sees_assigned_tickets(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo('ticket.start');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'status' => 'active']);

        $otherTechnician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $myTicket = $this->makeOpenTicket($company, $technician);
        $myTicket->update(['assigned_to' => $technician->id, 'status' => 'assigned']);

        $otherTicket = $this->makeOpenTicket($company, $technician);
        $otherTicket->update(['assigned_to' => $otherTechnician->id, 'status' => 'assigned']);

        $mySpk = $this->makeUnassignedSpk($company, $technician);
        $mySpk->update(['assigned_to' => $technician->id, 'status' => 'assigned']);

        $this->actingAs($technician)
            ->get(route('admin.my-work.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->has('items.data', 1)
                ->where('items.data.0.domain', 'ticket')
                ->where('items.data.0.id', $myTicket->public_id)
            );
    }

    public function test_technician_with_spk_permission_only_sees_assigned_spk(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo('spk.start');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'status' => 'active']);

        $otherTechnician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $myTicket = $this->makeOpenTicket($company, $technician);
        $myTicket->update(['assigned_to' => $technician->id, 'status' => 'assigned']);

        $mySpk = $this->makeUnassignedSpk($company, $technician);
        $mySpk->update(['assigned_to' => $technician->id, 'status' => 'assigned']);

        $otherSpk = $this->makeUnassignedSpk($company, $technician);
        $otherSpk->update(['assigned_to' => $otherTechnician->id, 'status' => 'assigned']);

        $this->actingAs($technician)
            ->get(route('admin.my-work.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->has('items.data', 1)
                ->where('items.data.0.domain', 'spk')
                ->where('items.data.0.id', $mySpk->public_id)
            );
    }

    public function test_technician_with_both_permissions_sees_both(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo(['ticket.start', 'spk.start']);
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'status' => 'active']);

        $ticket = $this->makeOpenTicket($company, $technician);
        $ticket->update(['assigned_to' => $technician->id, 'status' => 'assigned']);

        $spk = $this->makeUnassignedSpk($company, $technician);
        $spk->update(['assigned_to' => $technician->id, 'status' => 'assigned']);

        $this->actingAs($technician)
            ->get(route('admin.my-work.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->has('items.data', 2)
            );
    }

    public function test_my_work_tenant_isolation(): void
    {
        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);

        $techA = User::factory()->create([
            'company_id' => $companyA->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $techA->givePermissionTo(['ticket.start', 'spk.start']);
        EmployeeProfile::factory()->create(['company_id' => $companyA->id, 'user_id' => $techA->id, 'status' => 'active']);

        $techB = User::factory()->create([
            'company_id' => $companyB->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $techB->givePermissionTo(['ticket.start', 'spk.start']);

        $ticketA = $this->makeOpenTicket($companyA, $techA);
        $ticketA->update(['assigned_to' => $techA->id, 'status' => 'assigned']);

        $ticketB = $this->makeOpenTicket($companyB, $techB);
        $ticketB->update(['assigned_to' => $techB->id, 'status' => 'assigned']);

        $this->actingAs($techA)
            ->get(route('admin.my-work.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->has('items.data', 1)
                ->where('items.data.0.id', $ticketA->public_id)
            );
    }

    public function test_my_work_summary_and_badge_count_accurate(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $tech = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $tech->givePermissionTo(['ticket.start', 'ticket.resolve', 'spk.start']);
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $tech->id, 'status' => 'active']);

        $ticketAssigned = $this->makeOpenTicket($company, $tech);
        $ticketAssigned->update(['assigned_to' => $tech->id, 'status' => 'assigned']);

        $ticketInProgress = $this->makeOpenTicket($company, $tech);
        $ticketInProgress->update(['assigned_to' => $tech->id, 'status' => 'on_progress']);

        $spkAssigned = $this->makeUnassignedSpk($company, $tech);
        $spkAssigned->update(['assigned_to' => $tech->id, 'status' => 'assigned']);

        $this->actingAs($tech)
            ->get(route('admin.my-work.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->where('summary.total', 3)
                ->where('summary.assigned', 2)
                ->where('summary.in_progress', 1)
            );

        $request = Request::create(route('admin.my-work.index'));
        $request->setUserResolver(fn () => $tech);
        CompanyService::useCompanyForTests($company->id);

        $this->assertSame(3, MyWorkQuery::badgeCount($request));
    }

    public function test_my_work_badge_count_returns_null_for_ineligible_user(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $request = Request::create(route('admin.my-work.index'));
        $request->setUserResolver(fn () => $user);

        $this->assertNull(MyWorkQuery::badgeCount($request));
    }

    public function test_my_work_domain_filtering(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $tech = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $tech->givePermissionTo(['ticket.start', 'spk.start']);
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $tech->id, 'status' => 'active']);

        $ticket = $this->makeOpenTicket($company, $tech);
        $ticket->update(['assigned_to' => $tech->id, 'status' => 'assigned']);

        $spk = $this->makeUnassignedSpk($company, $tech);
        $spk->update(['assigned_to' => $tech->id, 'status' => 'assigned']);

        $this->actingAs($tech)
            ->get(route('admin.my-work.index', ['domain' => 'ticket']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->has('items.data', 1)
                ->where('items.data.0.domain', 'ticket')
            );

        $this->actingAs($tech)
            ->get(route('admin.my-work.index', ['domain' => 'spk']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->has('items.data', 1)
                ->where('items.data.0.domain', 'spk')
            );
    }

    public function test_waiting_action_summary_and_filter_have_identical_rows(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $tech = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $tech->givePermissionTo(['ticket.close', 'spk.submit']);
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $tech->id, 'status' => 'active']);

        $resolvedTicket = $this->makeOpenTicket($company, $tech);
        $resolvedTicket->update(['assigned_to' => $tech->id, 'status' => 'resolved']);

        $inProgressSpk = $this->makeUnassignedSpk($company, $tech);
        $inProgressSpk->update(['assigned_to' => $tech->id, 'status' => 'in_progress']);

        $request = Request::create(route('admin.my-work.index'));
        $request->setUserResolver(fn () => $tech);
        CompanyService::useCompanyForTests($company->id);

        $summary = MyWorkQuery::summary($request);
        $this->assertSame(2, $summary['waiting_action']);

        $response = $this->actingAs($tech)
            ->get(route('admin.my-work.index', ['status' => 'resolved']))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('items.data', function (Collection $items) use ($resolvedTicket, $inProgressSpk): bool {
                return $items->pluck('composite_id')->sort()->values()->all() === collect([
                    "ticket:{$resolvedTicket->public_id}",
                    "spk:{$inProgressSpk->public_id}",
                ])->sort()->values()->all();
            })
        );
    }

    public function test_can_my_work_is_server_authoritative_and_fail_closed(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $tech = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        CompanyService::useCompanyForTests($company->id);
        $tech->givePermissionTo('spk.submit');
        $request = Request::create('/');
        $request->setUserResolver(fn () => $tech);

        $this->assertFalse((app(HandleInertiaRequests::class)->share($request)['canMyWork'])());

        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $tech->id, 'status' => 'active']);
        $this->assertTrue((app(HandleInertiaRequests::class)->share($request)['canMyWork'])());

        $tech->employeeProfile->update(['company_id' => Company::factory()->create()->id]);
        $this->assertFalse((app(HandleInertiaRequests::class)->share($request)['canMyWork'])());
    }

    public function test_can_dispatch_flag_populated_based_on_eligibility(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $tech = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $tech->givePermissionTo('ticket.start');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $tech->id, 'status' => 'active']);

        $this->actingAs($tech)
            ->get(route('admin.my-work.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->where('canDispatch', false)
                ->where('dispatchSummary', null)
            );

        $tech->givePermissionTo('ticket.assign');

        $this->actingAs($tech)
            ->get(route('admin.my-work.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->where('canDispatch', true)
                ->has('dispatchSummary')
            );
    }

    public function test_unified_my_work_pagination_clamps_arbitrary_page_to_bounded_fetch_window(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo(['ticket.start', 'spk.start']);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        $ticket = $this->makeOpenTicket($company, $technician);
        $ticket->update(['assigned_to' => $technician->id, 'status' => 'assigned']);
        $spk = $this->makeUnassignedSpk($company, $technician);
        $spk->update(['assigned_to' => $technician->id, 'status' => 'assigned']);

        $this->actingAs($technician)
            ->get(route('admin.my-work.index', ['page' => 999, 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MyWork/Index')
                ->where('items.meta.current_page', 100)
                ->where('items.data', function (Collection $items): bool {
                    return $items->isEmpty();
                })
            );
    }

    public function test_unified_my_work_collection_paths_hard_bound_each_source_fetch(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo(['ticket.start', 'spk.start']);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        $this->makeOpenTicket($company, $technician)->update([
            'assigned_to' => $technician->id,
            'status' => 'assigned',
        ]);
        $this->makeUnassignedSpk($company, $technician)->update([
            'assigned_to' => $technician->id,
            'status' => 'assigned',
        ]);

        $request = Request::create(route('admin.my-work.index'));
        $request->setUserResolver(fn () => $technician);
        CompanyService::useCompanyForTests($company->id);
        $query = MyWorkQuery::filtered($request);

        $this->assertInstanceOf(MyWorkUnifiedQuery::class, $query);

        $boundedSql = [];
        DB::listen(function (QueryExecuted $event) use (&$boundedSql): void {
            $sql = strtolower($event->sql);
            if (str_contains($sql, ' from ') && (str_contains($sql, 'tickets') || str_contains($sql, 'work_orders'))) {
                $boundedSql[] = $sql;
            }
        });

        $query->get();
        $query->first();
        iterator_to_array($query->getIterator());

        $this->assertCount(6, $boundedSql);
        foreach ($boundedSql as $sql) {
            $this->assertMatchesRegularExpression('/limit\s+1000(?:\s|$)/', $sql);
        }
    }

    public function test_stale_ticket_assignment_is_an_inertia_conflict(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('ticket.assign');
        $this->grantRole($dispatcher, 'admin');

        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo('ticket.start');
        $this->grantRole($technician, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'status' => 'active']);

        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $ticket->update(['assigned_to' => $dispatcher->id, 'status' => 'assigned']);

        $this->actingAs($dispatcher)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('admin.tickets.assign', $ticket), [
                'handler_id' => $technician->public_id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $ticket->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['conflict']);
    }

    public function test_stale_spk_assignment_is_an_inertia_conflict(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('spk.assign');
        $this->grantRole($dispatcher, 'admin');

        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo('spk.start');
        $this->grantRole($technician, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'status' => 'active']);

        $workOrder = $this->makeUnassignedSpk($company, $dispatcher);
        $workOrder->update(['assigned_to' => $dispatcher->id, 'status' => 'assigned']);

        $this->actingAs($dispatcher)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('admin.spk.assign', $workOrder), [
                'technician_id' => $technician->public_id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $workOrder->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['conflict']);
    }

    public function test_locked_ticket_start_race_is_an_inertia_conflict(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo('ticket.start');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'status' => 'active']);

        $ticket = $this->makeOpenTicket($company, $technician);
        $ticket->update(['assigned_to' => $technician->id, 'status' => 'assigned']);

        $ticketSelects = 0;
        DB::listen(function (QueryExecuted $query) use (&$ticketSelects, $ticket): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, ' from ') && str_contains($sql, 'tickets') && str_contains($sql, 'limit 1')) {
                $ticketSelects++;
                if ($ticketSelects === 1) {
                    DB::table('tickets')->where('id', $ticket->id)->update(['status' => 'on_progress']);
                }
            }
        });

        $response = $this->actingAs($technician)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('admin.tickets.start', $ticket));

        $this->assertGreaterThanOrEqual(2, $ticketSelects);
        $response->assertRedirect()->assertSessionHasErrors(['conflict']);
    }

    public function test_locked_spk_start_race_is_an_inertia_conflict(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo('spk.start');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'status' => 'active']);

        $workOrder = $this->makeUnassignedSpk($company, $technician);
        $workOrder->update(['assigned_to' => $technician->id, 'status' => 'assigned']);

        $triggered = false;
        DB::listen(function (QueryExecuted $query) use (&$triggered, $workOrder): void {
            $sql = strtolower($query->sql);
            if (! $triggered && str_contains($sql, ' from ') && str_contains($sql, 'work_orders')) {
                $triggered = true;
                DB::table('work_orders')->where('id', $workOrder->id)->update(['status' => 'in_progress']);
            }
        });

        $this->actingAs($technician)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('admin.spk.start', $workOrder))
            ->assertRedirect()
            ->assertSessionHasErrors(['conflict']);
    }

    public function test_ordinary_ticket_state_validation_is_a_flash_error_and_not_a_conflict(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo(['ticket.assign', 'ticket.reassign']);
        $this->grantRole($dispatcher, 'admin');

        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->givePermissionTo('ticket.start');
        $this->grantRole($technician, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'status' => 'active']);

        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $ticket->update(['assigned_to' => $dispatcher->id, 'status' => 'closed']);

        $this->actingAs($dispatcher)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('admin.tickets.assign', $ticket), [
                'handler_id' => $technician->public_id,
                'expected_assigned_to' => $dispatcher->public_id,
                'expected_updated_at' => $ticket->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            // A refusal the code worded for the user returns to the page with that sentence in the toast (bootstrap/app.php),
            // and is not a stale-write conflict: no `conflict` error.
            ->assertRedirect()
            ->assertSessionHas('error', 'Tiket harus berstatus terbuka atau ditugaskan untuk bisa ditugaskan.')
            ->assertSessionDoesntHaveErrors('conflict');
    }

    private function makeOpenTicket(Company $company, User $creator): Ticket
    {
        CompanyService::useCompanyForTests($company->id);

        $category = TicketCategory::create([
            'company_id' => $company->id,
            'name' => 'General Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return TicketService::create([
            'title' => 'Internet trouble',
            'description' => 'Cannot connect',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
        ], $creator->id);
    }

    private function makeUnassignedSpk(Company $company, User $creator): WorkOrder
    {
        CompanyService::useCompanyForTests($company->id);

        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-MYW-'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'MyWork Location',
            'type' => 'site',
            'path' => 'MyWork',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        return WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'draft',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $creator->id,
            'assigned_to' => null,
        ]);
    }
}
