<?php

namespace Tests\Feature\Admin;

use App\Http\Resources\DispatchItemResource;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\User;
use App\Queries\Admin\DispatchQueueQuery;
use App\Queries\Admin\DispatchQueueUnifiedQuery;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

class DispatchQueueTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        foreach (['ticket.assign', 'ticket.reassign', 'spk.assign', 'spk.reassign', 'ticket.start', 'ticket.resolve', 'ticket.close', 'spk.start', 'spk.submit'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    public function test_unauthenticated_user_cannot_access_dispatch_queue(): void
    {
        $this->get(route('admin.dispatch.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_without_dispatch_and_without_mywork_permission_is_forbidden(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole(Role::where('name', 'customer')->firstOrFail());

        $this->actingAs($user)
            ->get(route('admin.dispatch.index'))
            ->assertForbidden();
    }

    public function test_user_with_only_mywork_permission_is_redirected_to_my_work(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo('ticket.start');
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('admin.dispatch.index'))
            ->assertRedirect(route('admin.my-work.index'));
    }

    public function test_dispatcher_with_ticket_assign_only_sees_tickets_and_no_spk(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('ticket.assign');

        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $spk = $this->makeUnassignedSpk($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dispatch/Index')
                ->has('items.data', 1)
                ->where('items.data.0.domain', 'ticket')
                ->where('items.data.0.id', $ticket->id)
                ->where('domains', [['value' => 'ticket', 'label' => 'Tiket']])
                ->where('technicians', [])
            );
    }

    public function test_dispatcher_with_spk_assign_only_sees_spk_and_no_tickets(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('spk.assign');

        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $spk = $this->makeUnassignedSpk($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dispatch/Index')
                ->has('items.data', 1)
                ->where('items.data.0.domain', 'spk')
                ->where('items.data.0.id', $spk->id)
                ->where('domains', [['value' => 'spk', 'label' => 'SPK']])
                ->where('handlers', [])
            );
    }

    public function test_dispatcher_with_both_permissions_sees_both(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo(['ticket.assign', 'spk.assign']);

        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $spk = $this->makeUnassignedSpk($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dispatch/Index')
                ->has('items.data', 2)
                ->where('domains', [
                    ['value' => 'ticket', 'label' => 'Tiket'],
                    ['value' => 'spk', 'label' => 'SPK'],
                ])
            );
    }

    public function test_dispatcher_with_assign_and_reassign_permissions_keeps_summary_and_items_consistent_without_status(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo([
            'ticket.assign',
            'ticket.reassign',
            'spk.assign',
            'spk.reassign',
        ]);

        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $spk = $this->makeUnassignedSpk($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.unassigned', 2)
                ->where('summary.ticket_unassigned', 1)
                ->where('summary.spk_unassigned', 1)
                ->where('items.meta.total', 2)
                ->has('items.data', 2)
                ->where('items.data', function ($items) use ($ticket, $spk): bool {
                    return $items->contains(fn ($item): bool => $item['id'] === $ticket->id && $item['domain'] === 'ticket')
                        && $items->contains(fn ($item): bool => $item['id'] === $spk->id && $item['domain'] === 'spk');
                })
            );
    }

    public function test_dispatch_explicit_status_filters_override_default_assignment_semantics(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo([
            'ticket.assign',
            'ticket.reassign',
            'spk.assign',
            'spk.reassign',
        ]);
        $handler = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $unassignedTicket = $this->makeOpenTicket($company, $dispatcher);
        $assignedTicket = $this->makeOpenTicket($company, $dispatcher);
        $assignedTicket->update(['assigned_to' => $handler->id, 'status' => 'assigned']);
        $unassignedSpk = $this->makeUnassignedSpk($company, $dispatcher);
        $assignedSpk = $this->makeUnassignedSpk($company, $dispatcher);
        $assignedSpk->update(['assigned_to' => $handler->id, 'status' => 'assigned']);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index', ['status' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.meta.total', 2)
                ->has('items.data', 2)
                ->where('items.data', function ($items) use ($unassignedTicket, $unassignedSpk): bool {
                    return $items->contains(fn ($item): bool => $item['id'] === $unassignedTicket->id)
                        && $items->contains(fn ($item): bool => $item['id'] === $unassignedSpk->id);
                })
            );

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index', ['status' => 'assigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.meta.total', 2)
                ->has('items.data', 2)
                ->where('items.data', function ($items) use ($assignedTicket, $assignedSpk): bool {
                    return $items->contains(fn ($item): bool => $item['id'] === $assignedTicket->id)
                        && $items->contains(fn ($item): bool => $item['id'] === $assignedSpk->id);
                })
            );

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.meta.total', 4)
                ->has('items.data', 4)
            );
    }

    public function test_dispatch_tenant_isolation_company_a_cannot_see_company_b(): void
    {
        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);

        $dispatcherA = User::factory()->create([
            'company_id' => $companyA->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcherA->givePermissionTo(['ticket.assign', 'spk.assign']);

        $dispatcherB = User::factory()->create([
            'company_id' => $companyB->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcherB->givePermissionTo(['ticket.assign', 'spk.assign']);

        $ticketA = $this->makeOpenTicket($companyA, $dispatcherA);
        $ticketB = $this->makeOpenTicket($companyB, $dispatcherB);

        $this->actingAs($dispatcherA)
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $ticketA->id)
            );
    }

    public function test_dispatch_summary_and_badge_count_accurate(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo(['ticket.assign', 'spk.assign']);

        $ticket1 = $this->makeOpenTicket($company, $dispatcher);
        $ticket2 = $this->makeOpenTicket($company, $dispatcher);
        $spk1 = $this->makeUnassignedSpk($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.unassigned', 3)
                ->where('summary.ticket_unassigned', 2)
                ->where('summary.spk_unassigned', 1)
            );

        $request = Request::create(route('admin.dispatch.index'));
        $request->setUserResolver(fn () => $dispatcher);
        CompanyService::useCompanyForTests($company->id);

        $this->assertSame(3, DispatchQueueQuery::badgeCount($request));
    }

    public function test_dispatch_spk_technicians_require_active_same_company_employee_profile(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $foreignCompany = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('spk.assign');

        $eligible = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $eligible->givePermissionTo('spk.start');
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $eligible->id,
            'status' => 'active',
        ]);

        $inactive = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $inactive->givePermissionTo('spk.start');
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $inactive->id,
            'status' => 'inactive',
        ]);

        $crossCompanyProfile = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $crossCompanyProfile->givePermissionTo('spk.start');
        EmployeeProfile::factory()->create([
            'company_id' => $foreignCompany->id,
            'user_id' => $crossCompanyProfile->id,
            'status' => 'active',
        ]);

        $crossCompanyUser = User::factory()->create(['company_id' => $foreignCompany->id, 'is_active' => true]);
        $crossCompanyUser->givePermissionTo('spk.start');
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $crossCompanyUser->id,
            'status' => 'active',
        ]);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dispatch/Index')
                ->where('technicians', function (Collection $technicians) use ($eligible, $inactive, $crossCompanyProfile, $crossCompanyUser): bool {
                    $ids = collect($technicians)->pluck('id')->all();

                    return in_array($eligible->id, $ids, true)
                        && ! in_array($inactive->id, $ids, true)
                        && ! in_array($crossCompanyProfile->id, $ids, true)
                        && ! in_array($crossCompanyUser->id, $ids, true);
                })
            );
    }

    public function test_unified_dispatch_pagination_clamps_arbitrary_page_to_bounded_fetch_window(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo(['ticket.assign', 'spk.assign']);

        $this->makeOpenTicket($company, $dispatcher);
        $this->makeUnassignedSpk($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index', ['page' => 999, 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dispatch/Index')
                ->where('items.meta.current_page', 100)
                ->where('items.data', function (Collection $items): bool {
                    return $items->isEmpty();
                })
            );
    }

    public function test_unified_dispatch_collection_paths_hard_bound_each_source_fetch(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo(['ticket.assign', 'spk.assign']);

        $this->makeOpenTicket($company, $dispatcher);
        $this->makeUnassignedSpk($company, $dispatcher);

        $request = Request::create(route('admin.dispatch.index'));
        $request->setUserResolver(fn () => $dispatcher);
        CompanyService::useCompanyForTests($company->id);
        $query = DispatchQueueQuery::filtered($request);

        $this->assertInstanceOf(DispatchQueueUnifiedQuery::class, $query);

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

    public function test_dispatch_badge_count_returns_null_in_shared_props_for_ineligible_user(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo('ticket.start');

        $request = Request::create(route('admin.my-work.index'));
        $request->setUserResolver(fn () => $user);

        $this->assertNull(DispatchQueueQuery::badgeCount($request));
    }

    public function test_dispatch_resource_emits_canonical_updated_at_tokens_accepted_by_ticket_and_spk_assignment(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo(['ticket.assign', 'spk.assign']);

        $technician = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $technician->assignRole('technician');
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $spk = $this->makeUnassignedSpk($company, $dispatcher);
        $request = Request::create(route('admin.dispatch.index'));
        $request->setUserResolver(fn () => $dispatcher);
        $ticketPayload = DispatchItemResource::make($ticket)->toArray($request);
        $spkPayload = DispatchItemResource::make($spk)->toArray($request);

        $this->assertMatchesRegularExpression(
            '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{3}Z$/',
            $ticketPayload['updated_at'],
        );
        $this->assertMatchesRegularExpression(
            '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{3}Z$/',
            $spkPayload['updated_at'],
        );

        $this->actingAs($dispatcher)
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'ticket',
                'id' => $ticket->id,
                'assignee_id' => $technician->id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $ticketPayload['updated_at'],
            ])
            ->assertRedirect();

        $this->actingAs($dispatcher)
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'spk',
                'id' => $spk->id,
                'assignee_id' => $technician->id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $spkPayload['updated_at'],
            ])
            ->assertRedirect();
    }

    public function test_dispatch_assign_mutation_reuses_canonical_service_for_ticket(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('ticket.assign');

        $handler = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $handler->assignRole('technician');

        $ticket = $this->makeOpenTicket($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'ticket',
                'id' => $ticket->id,
                'assignee_id' => $handler->id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $ticket->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertRedirect();

        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertSame($handler->id, $ticket->fresh()->assigned_to);
    }

    public function test_dispatch_assign_mutation_reuses_canonical_service_for_spk(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('spk.assign');

        $technician = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $technician->assignRole('technician');
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        $spk = $this->makeUnassignedSpk($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'spk',
                'id' => $spk->id,
                'assignee_id' => $technician->id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $spk->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertRedirect();

        $this->assertSame('assigned', $spk->fresh()->status);
        $this->assertSame($technician->id, $spk->fresh()->assigned_to);
    }

    public function test_dispatch_assign_mutation_rejects_stale_conflict_as_inertia_validation_error(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('ticket.assign');

        $handler = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $handler->assignRole('technician');

        $ticket = $this->makeOpenTicket($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->withHeader('X-Inertia', 'true')
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'ticket',
                'id' => $ticket->id,
                'assignee_id' => $handler->id,
                'expected_assigned_to' => null,
                'expected_updated_at' => now()->subMinute()->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertSessionHasErrors('conflict')
            ->assertStatus(302);
    }

    public function test_dispatch_assign_requires_explicit_null_and_updated_at_guards(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('ticket.assign');
        $handler = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $handler->assignRole('technician');
        $ticket = $this->makeOpenTicket($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'ticket',
                'id' => $ticket->id,
                'assignee_id' => $handler->id,
            ])
            ->assertSessionHasErrors(['expected_assigned_to', 'expected_updated_at']);
    }

    public function test_dispatch_assign_mutation_rejects_cross_tenant(): void
    {
        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);

        $dispatcherA = User::factory()->create([
            'company_id' => $companyA->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcherA->givePermissionTo('ticket.assign');

        $dispatcherB = User::factory()->create([
            'company_id' => $companyB->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcherB->givePermissionTo('ticket.assign');

        $handlerA = User::factory()->create([
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);
        $handlerA->assignRole('technician');

        $ticketB = $this->makeOpenTicket($companyB, $dispatcherB);

        // Dispatcher A cannot assign ticket belonging to Company B
        $this->actingAs($dispatcherA)
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'ticket',
                'id' => $ticketB->id,
                'assignee_id' => $handlerA->id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $ticketB->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertNotFound();
    }

    public function test_dispatch_assign_rejects_a_user_of_another_company_under_every_assignee_field(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $foreignCompany = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo(['ticket.assign', 'spk.assign']);

        // Built the same way: only the company differs, so only the company can be what rejects the foreign one.
        $foreignTechnician = $this->technician($foreignCompany);
        $ownTechnician = $this->technician($company);

        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $spk = $this->makeUnassignedSpk($company, $dispatcher);
        Notification::fake();

        foreach ([$ticket, $spk] as $item) {
            foreach (['assignee_id', 'handler_id', 'technician_id'] as $field) {
                $this->postAssign($dispatcher, $item, [$field => $foreignTechnician->id])->assertNotFound();
            }
        }

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => null, 'status' => 'open']);
        $this->assertDatabaseHas('work_orders', ['id' => $spk->id, 'assigned_to' => null, 'status' => 'draft']);
        $this->assertSame(0, WorkOrderAssignment::withoutCompany()->where('work_order_id', $spk->id)->count());
        Notification::assertNothingSent();

        $this->postAssign($dispatcher, $ticket, ['assignee_id' => $ownTechnician->id])->assertRedirect();
        $this->postAssign($dispatcher, $spk, ['assignee_id' => $ownTechnician->id])->assertRedirect();
        $this->assertSame($ownTechnician->id, $ticket->fresh()->assigned_to);
        $this->assertSame($ownTechnician->id, $spk->fresh()->assigned_to);
    }

    public function test_dispatch_assign_rejects_same_company_users_the_eligible_lists_do_not_offer(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $foreignCompany = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo(['ticket.assign', 'spk.assign']);

        $eligible = $this->technician($company);
        $viewer = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $viewer->givePermissionTo(['ticket.view', 'spk.view']);
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'status' => 'active']);

        // Rejected for both domains.
        $rejectedEverywhere = [
            'inactive user' => $this->technician($company, ['is_active' => false]),
            'no start or submit permission' => $viewer,
        ];
        // Valid ticket handlers, but not SPK technicians: the employee profile is missing, inactive or of another company.
        $rejectedForSpk = [
            'no employee profile' => $this->technician($company, profile: null),
            'inactive employee profile' => $this->technician($company, profile: ['status' => 'inactive']),
            'employee profile of another company' => $this->technician($company, profile: ['company_id' => $foreignCompany->id]),
        ];

        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $spk = $this->makeUnassignedSpk($company, $dispatcher);

        $page = $this->actingAs($dispatcher)->get(route('admin.dispatch.index'))->assertOk();
        $handlers = array_column($page->inertiaProps('handlers'), 'id');
        $technicians = array_column($page->inertiaProps('technicians'), 'id');
        $this->assertContains($eligible->id, $handlers);
        $this->assertContains($eligible->id, $technicians);

        foreach ($rejectedEverywhere as $label => $user) {
            $this->assertNotContains($user->id, $handlers, "{$label} is listed as a ticket handler");
            $this->assertSame(404, $this->postAssign($dispatcher, $ticket, ['assignee_id' => $user->id])->getStatusCode(), "{$label} was assigned to a ticket");
        }

        foreach ([...$rejectedEverywhere, ...$rejectedForSpk] as $label => $user) {
            $this->assertNotContains($user->id, $technicians, "{$label} is listed as an SPK technician");
            $this->assertSame(404, $this->postAssign($dispatcher, $spk, ['assignee_id' => $user->id])->getStatusCode(), "{$label} was assigned to an SPK");
        }

        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertNull($spk->fresh()->assigned_to);

        $this->postAssign($dispatcher, $ticket, ['assignee_id' => $eligible->id])->assertRedirect();
        $this->postAssign($dispatcher, $spk, ['assignee_id' => $eligible->id])->assertRedirect();
        $this->assertSame($eligible->id, $ticket->fresh()->assigned_to);
        $this->assertSame($eligible->id, $spk->fresh()->assigned_to);
    }

    public function test_dispatch_assign_mutation_rejects_forbid_self_assignment_when_configured(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'settings' => ['forbid_self_assignment' => true],
        ]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->assignRole('admin');
        $dispatcher->givePermissionTo(['ticket.assign', 'ticket.start']);

        $ticket = $this->makeOpenTicket($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'ticket',
                'id' => $ticket->id,
                'assignee_id' => $dispatcher->id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $ticket->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertStatus(422);
    }

    public function test_inactive_dispatcher_cannot_assign_through_shared_entrypoint(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => false,
        ]);
        $dispatcher->givePermissionTo('ticket.assign');
        $handler = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $handler->assignRole('technician');
        $ticket = $this->makeOpenTicket($company, $dispatcher);

        $this->actingAs($dispatcher)
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'ticket',
                'id' => $ticket->id,
                'assignee_id' => $handler->id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $ticket->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'assigned_to' => null,
            'status' => 'open',
        ]);
    }

    public function test_spk_reassign_permission_lists_assigned_items_by_default(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('spk.reassign');
        $handler = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $handler->assignRole('technician');
        $spk = $this->makeUnassignedSpk($company, $dispatcher);
        $spk->update(['assigned_to' => $handler->id, 'status' => 'assigned']);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $spk->id)
                ->where('items.data.0.actions.assign', true)
            );
    }

    public function test_reassign_permission_only_lists_assigned_items_and_audits_once(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $dispatcher = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $dispatcher->givePermissionTo('ticket.reassign');
        $oldHandler = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $oldHandler->assignRole('technician');
        $newHandler = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $newHandler->assignRole('technician');
        $ticket = $this->makeOpenTicket($company, $dispatcher);
        $ticket->update(['assigned_to' => $oldHandler->id, 'status' => 'assigned']);

        $this->actingAs($dispatcher)
            ->get(route('admin.dispatch.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $ticket->id)
                ->where('items.data.0.actions.assign', true)
            );

        $this->actingAs($dispatcher)
            ->post(route('admin.dispatch.assign'), [
                'domain' => 'ticket',
                'id' => $ticket->id,
                'assignee_id' => $newHandler->id,
                'expected_assigned_to' => $oldHandler->id,
                'expected_updated_at' => $ticket->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertRedirect();

        $this->assertSame(1, Activity::query()
            ->where('subject_type', $ticket->getMorphClass())
            ->where('subject_id', $ticket->id)
            ->where('log_name', 'ticket')
            ->where('description', 'assigned')
            ->count());
    }

    /**
     * @param  array<string, int>  $assignee  the assignee under whichever key the caller exercises
     */
    private function postAssign(User $actor, Ticket|WorkOrder $item, array $assignee): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.dispatch.assign'), [
            'domain' => $item instanceof Ticket ? 'ticket' : 'spk',
            'id' => $item->id,
            'expected_assigned_to' => null,
            'expected_updated_at' => $item->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ...$assignee,
        ]);
    }

    /**
     * An eligible technician of the company; each override breaks one rule, and a null $profile leaves the
     * employee profile out.
     *
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>|null  $profile
     */
    private function technician(Company $company, array $user = [], ?array $profile = []): User
    {
        $technician = User::factory()->create(['company_id' => $company->id, 'is_active' => true, ...$user]);
        $technician->assignRole('technician');

        if ($profile !== null) {
            EmployeeProfile::factory()->create([
                'company_id' => $company->id,
                'user_id' => $technician->id,
                'status' => 'active',
                ...$profile,
            ]);
        }

        return $technician;
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
            'code' => 'LOC-DISP-'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'Dispatch Location',
            'type' => 'site',
            'path' => 'Dispatch',
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
