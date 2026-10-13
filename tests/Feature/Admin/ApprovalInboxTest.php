<?php

namespace Tests\Feature\Admin;

use App\Http\Resources\ApprovalInboxItemResource;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use App\Queries\Admin\ApprovalInboxQuery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

class ApprovalInboxTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        foreach (['spk.approve', 'spk.reject'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    public function test_approval_inbox_only_lists_waiting_review_spk_for_company(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $waiting = $this->waitingSpk($company, $creator);
        $this->waitingSpk($company, $creator, ['status' => 'in_progress']);
        $this->waitingSpk($company, $creator, ['status' => 'completed']);

        $otherCompany = Company::factory()->create();
        $otherCreator = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);
        $this->waitingSpk($otherCompany, $otherCreator);

        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Approvals/Index')
                ->has('items.data', 1)
                ->where('items.data.0.id', $waiting->id)
                ->where('items.data.0.domain', 'spk')
                ->where('items.data.0.code', $waiting->code)
                ->where('items.data.0.actions.approve', true)
            );
    }

    public function test_actor_without_approval_permission_is_forbidden(): void
    {
        [$company] = $this->approver();
        $viewer = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $viewer->assignRole(Role::where('name', 'technician')->firstOrFail());

        $this->actingAs($viewer)
            ->get(route('admin.approvals.index'))
            ->assertForbidden();
    }

    public function test_creator_and_assigned_technician_are_not_actionable_in_inbox(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $owned = $this->waitingSpk($company, $creator, ['code' => 'SPK-TEST-0001']);
        $owned->update(['assigned_to' => $manager->id]);

        $other = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $mine = $this->waitingSpk($company, $other, ['code' => 'SPK-TEST-0002']);
        $mine->update(['created_by' => $manager->id, 'assigned_to' => null]);

        // Maker-checker rows must not appear in the manager's inbox list
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'code', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 0)
            );

        // Direct resource payload check: action flags must be false
        $request = Request::create(route('admin.approvals.index'));
        $request->setUserResolver(fn () => $manager);

        $ownedResource = (new ApprovalInboxItemResource($owned))->toArray($request);
        $this->assertFalse($ownedResource['actions']['approve']);
        $this->assertFalse($ownedResource['actions']['reject']);

        $mineResource = (new ApprovalInboxItemResource($mine))->toArray($request);
        $this->assertFalse($mineResource['actions']['approve']);
        $this->assertFalse($mineResource['actions']['reject']);
    }

    public function test_role_matrix_exact_permissions_for_approve_and_reject_and_crafted_direct_post(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $spk = $this->waitingSpk($company, $creator, ['code' => 'SPK-ROLE-001']);

        // Actor 1: approve-only
        $approverOnly = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $approverOnly->givePermissionTo('spk.approve');

        // Can access inbox via OR permission, but actions are exact
        $this->actingAs($approverOnly)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.actions.approve', true)
                ->where('items.data.0.actions.reject', false)
            );

        // Approve-only actor crafted direct POST to reject is 403 fail-closed
        $this->actingAs($approverOnly)
            ->post(route('admin.spk.reject', $spk), ['reason' => 'Crafted reject attempt'])
            ->assertForbidden();

        // Actor 2: reject-only
        $spk2 = $this->waitingSpk($company, $creator, ['code' => 'SPK-ROLE-002']);
        $rejecterOnly = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $rejecterOnly->givePermissionTo('spk.reject');

        // Can access inbox via OR permission, but actions are exact
        $this->actingAs($rejecterOnly)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 2)
                ->where('items.data.0.actions.approve', false)
                ->where('items.data.0.actions.reject', true)
            );

        // Reject-only actor crafted direct POST to approve is 403 fail-closed
        $this->actingAs($rejecterOnly)
            ->post(route('admin.spk.approve', $spk2))
            ->assertForbidden();

        // Valid actions succeed
        $this->actingAs($approverOnly)
            ->post(route('admin.spk.approve', $spk))
            ->assertRedirect();
        $this->assertEquals('completed', $spk->fresh()->status);

        $this->actingAs($rejecterOnly)
            ->post(route('admin.spk.reject', $spk2), ['reason' => 'Bukti pekerjaan tidak valid'])
            ->assertRedirect();
        $this->assertEquals('rejected', $spk2->fresh()->status);

        // Actor 3: unprivileged user (neither approve nor reject)
        $spk3 = $this->waitingSpk($company, $creator, ['code' => 'SPK-ROLE-003']);
        $unprivileged = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // Unprivileged crafted direct POST to approve and reject are 403 fail-closed
        $this->actingAs($unprivileged)
            ->post(route('admin.spk.approve', $spk3))
            ->assertForbidden();

        $this->actingAs($unprivileged)
            ->post(route('admin.spk.reject', $spk3), ['reason' => 'Unauthorized reject attempt'])
            ->assertForbidden();
    }

    public function test_maker_checker_single_source_of_truth_parity_and_leakage(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // 1. Eligible SPK (created by creator, not assigned to manager)
        $eligibleSpk = $this->waitingSpk($company, $creator, [
            'code' => 'SPK-ELIGIBLE',
            'type' => 'maintenance',
        ]);

        // 2. Non-eligible SPK: created by manager
        $selfCreatedSpk = $this->waitingSpk($company, $manager, [
            'code' => 'SPK-SELF-CREATED',
            'type' => 'installation',
        ]);

        // 3. Non-eligible SPK: assigned to manager
        $assignedSpk = $this->waitingSpk($company, $creator, [
            'code' => 'SPK-ASSIGNED-ME',
            'type' => 'relocation',
            'assigned_to' => $manager->id,
        ]);

        $request = Request::create(route('admin.approvals.index'));
        $request->setUserResolver(fn () => $manager);

        // Verify query level single source of truth
        $listItems = ApprovalInboxQuery::filtered($request)->get();
        $summary = ApprovalInboxQuery::summary($request);
        $badge = ApprovalInboxQuery::badgeCount($request);

        // Strict parity: LIST count === summary.waiting_for_me === badgeCount
        $this->assertCount(1, $listItems);
        $this->assertSame($eligibleSpk->id, $listItems->first()->id);
        $this->assertSame(1, $summary['waiting_for_me']);
        $this->assertSame(1, $badge);
        $this->assertSame($listItems->count(), $summary['waiting_for_me']);
        $this->assertSame($badge, $summary['waiting_for_me']);

        // Controller level verify: items, requesters, and types
        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $eligibleSpk->id)
                ->where('summary.waiting_for_me', 1)
                // Manager must NOT appear in requesters options
                ->has('requesters', 1)
                ->where('requesters.0.id', $creator->id)
                // Types must NOT leak installation or repair
                ->has('types', 1)
                ->where('types.0', 'maintenance')
            );

        // Direct crafted POST by creator/assignee remains fail-closed
        $this->actingAs($manager)
            ->post(route('admin.spk.approve', $selfCreatedSpk))
            ->assertStatus(422);

        $this->actingAs($manager)
            ->post(route('admin.spk.reject', $selfCreatedSpk), ['reason' => 'Reject own'])
            ->assertStatus(422);

        $this->actingAs($manager)
            ->post(route('admin.spk.approve', $assignedSpk))
            ->assertStatus(422);

        $this->actingAs($manager)
            ->post(route('admin.spk.reject', $assignedSpk), ['reason' => 'Reject assigned'])
            ->assertStatus(422);

        // Leakage test: filtering by creator who also created $assignedSpk only returns eligible SPK
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['creator' => $creator->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $eligibleSpk->id)
            );

        // Leakage test: filtering by manager (ineligible creator) is safely ignored and does not leak self-created
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['creator' => $manager->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $eligibleSpk->id)
            );
    }

    public function test_stale_or_double_action_on_inertia_request_returns_inertia_error_contract(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $spk = $this->waitingSpk($company, $creator);

        // First approval succeeds
        $this->actingAs($manager)
            ->post(route('admin.spk.approve', $spk))
            ->assertRedirect();

        $this->assertEquals('completed', $spk->fresh()->status);

        // Non-Inertia request without X-Inertia header gets standard HTTP 422
        $this->actingAs($manager)
            ->post(route('admin.spk.approve', $spk))
            ->assertStatus(422);

        // Stale second approval via Inertia request must return 302 redirect with session error,
        // NEVER a raw non-Inertia 422 invalid-response modal
        $approveResponse = $this->actingAs($manager)
            ->from(route('admin.approvals.index'))
            ->withHeader('X-Inertia', 'true')
            ->post(route('admin.spk.approve', $spk));

        $approveResponse->assertRedirect(route('admin.approvals.index'))
            ->assertSessionHasErrors([
                'error' => 'SPK must be waiting review to approve.',
            ]);

        // When Inertia client follows redirect, Inertia page receives errors.error prop
        $this->flushHeaders();
        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Approvals/Index')
                ->has('errors.error')
            );

        // Non-Inertia request without X-Inertia header gets standard HTTP 422
        $this->actingAs($manager)
            ->post(route('admin.spk.reject', $spk), ['reason' => 'Too late'])
            ->assertStatus(422);

        // Stale rejection via Inertia request must return 302 redirect with session error
        $rejectResponse = $this->actingAs($manager)
            ->from(route('admin.approvals.index'))
            ->withHeader('X-Inertia', 'true')
            ->post(route('admin.spk.reject', $spk), ['reason' => 'Too late']);

        $rejectResponse->assertRedirect(route('admin.approvals.index'))
            ->assertSessionHasErrors([
                'error' => 'SPK must be waiting review to reject.',
            ]);

        // When Inertia client follows redirect, Inertia page receives errors.error prop
        $this->flushHeaders();
        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Approvals/Index')
                ->has('errors.error')
            );
    }

    public function test_filters_are_server_side_and_non_requester_ids_are_ignored(): void
    {
        [$company, $manager] = $this->approver();
        $creatorA = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $creatorB = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $nonRequester = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $a = $this->waitingSpk($company, $creatorA, ['type' => 'installation', 'code' => 'SPK-TEST-0001']);
        $b = $this->waitingSpk($company, $creatorB, ['type' => 'maintenance', 'code' => 'SPK-TEST-0002']);

        $otherCompany = Company::factory()->create();
        $foreignCreator = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);
        $this->waitingSpk($otherCompany, $foreignCreator);

        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['type' => 'maintenance', 'creator' => $creatorB->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $b->id)
            );

        // Filter creator yang bukan requester company saat ini diabaikan agar
        // tidak menjadi oracle keberadaan lintas tenant.
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['creator' => $foreignCreator->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 2)
                ->where('items.data.0.id', $a->id)
                ->where('items.data.1.id', $b->id)
            );

        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['creator' => $nonRequester->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 2)
                ->where('items.data.0.id', $a->id)
                ->where('items.data.1.id', $b->id)
            );

        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['creator' => '999999']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 2)
                ->where('items.data.0.id', $a->id)
                ->where('items.data.1.id', $b->id)
            );
    }

    public function test_pagination_and_sort_allowlist(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $orders = [];
        for ($index = 1; $index <= 11; $index++) {
            $orders[] = $this->waitingSpk($company, $creator, [
                'code' => sprintf('SPK-TEST-%04d', $index),
                'title' => sprintf('Order %02d', $index),
            ]);
        }

        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'code', 'direction' => 'asc', 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 10)
                ->where('items.meta.total', 11)
                ->where('items.meta.per_page', 10)
                ->where('items.data.0.id', $orders[0]->id)
                ->where('items.data.9.id', $orders[9]->id)
            );

        // Sort tidak dikenal kembali ke default (created_at desc), sedangkan
        // ukuran halaman yang tidak didukung kembali ke 10 baris canonical.
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'password', 'per_page' => 7]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 10)
                ->where('items.meta.total', 11)
                ->where('items.meta.per_page', 10)
                ->where('filters.sort', 'password')
            );
    }

    public function test_summary_counts_are_company_scoped(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // Dua baris pertama tidak actionable bagi manager (creator = diri
        // sendiri atau assigned ke manager); hanya baris ketiga yang dihitung.
        $this->waitingSpk($company, $manager);
        $assignedToMe = $this->waitingSpk($company, $creator);
        $assignedToMe->update(['assigned_to' => $manager->id]);
        $this->waitingSpk($company, $creator);

        $otherCompany = Company::factory()->create();
        $otherCreator = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);
        $this->waitingSpk($otherCompany, $otherCreator);
        $this->waitingSpk($company, $creator, ['status' => 'completed']);
        $this->waitingSpk($otherCompany, $otherCreator, ['status' => 'rejected']);

        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.waiting_for_me', 1)
                ->where('summary.processed', 1)
            );
    }

    public function test_summary_processed_excludes_actor_self_created_and_assigned_rows(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // 1. Eligible processed rows (completed and rejected, created by another user, not assigned to manager)
        $this->waitingSpk($company, $creator, ['status' => 'completed']);
        $this->waitingSpk($company, $creator, ['status' => 'rejected']);

        // 2. Ineligible processed rows: self-created by the manager (maker)
        $this->waitingSpk($company, $manager, ['status' => 'completed']);
        $this->waitingSpk($company, $manager, ['status' => 'rejected']);

        // 3. Ineligible processed rows: assigned to the manager (checker)
        $assignedCompleted = $this->waitingSpk($company, $creator, ['status' => 'completed']);
        $assignedCompleted->update(['assigned_to' => $manager->id]);

        $assignedRejected = $this->waitingSpk($company, $creator, ['status' => 'rejected']);
        $assignedRejected->update(['assigned_to' => $manager->id]);

        // 4. Foreign company rows (must not be counted)
        $otherCompany = Company::factory()->create();
        $otherCreator = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);
        $this->waitingSpk($otherCompany, $otherCreator, ['status' => 'completed']);
        $this->waitingSpk($otherCompany, $otherCreator, ['status' => 'rejected']);

        // Direct query check
        $request = Request::create(route('admin.approvals.index'));
        $request->setUserResolver(fn () => $manager);
        $summary = ApprovalInboxQuery::summary($request);

        $this->assertSame(2, $summary['processed']);

        // HTTP Inertia check
        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.processed', 2)
            );
    }

    public function test_requester_options_are_company_scoped(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $this->waitingSpk($company, $creator);

        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('requesters', 1)
                ->where('requesters.0.id', $creator->id)
            );
    }

    public function test_badge_count_is_actor_scoped_and_tenant_isolated(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // SPK created by someone else, assigned_to null → actionable
        $this->waitingSpk($company, $creator);

        // SPK created by the manager → NOT actionable (maker-checker)
        $this->waitingSpk($company, $manager);

        // SPK assigned to the manager → NOT actionable (maker-checker)
        $assignedToMe = $this->waitingSpk($company, $creator);
        $assignedToMe->update(['assigned_to' => $manager->id]);

        // Other company SPK → NOT visible (tenant isolation)
        $otherCompany = Company::factory()->create();
        $otherCreator = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);
        $this->waitingSpk($otherCompany, $otherCreator);

        // Non-waiting_review SPK → NOT counted
        $this->waitingSpk($company, $creator, ['status' => 'in_progress']);

        $this->actingAs($manager)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workQueues.approvalPending', 1)
            );
    }

    public function test_badge_is_null_for_non_eligible_actor(): void
    {
        [$company] = $this->approver();
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->assignRole(Role::where('name', 'technician')->firstOrFail());

        $this->actingAs($technician)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workQueues.approvalPending', null)
            );
    }

    public function test_cross_tenant_cannot_see_or_action_foreign_spk(): void
    {
        [$companyA, $managerA] = $this->approver();
        [$companyB, $managerB] = $this->approver();

        $creatorA = User::factory()->create(['company_id' => $companyA->id, 'is_active' => true]);
        $spkA = $this->waitingSpk($companyA, $creatorA);

        // Manager B cannot see SPK A in inbox
        $this->actingAs($managerB)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 0)
                ->where('summary.waiting_for_me', 0)
            );

        // Manager B cannot approve foreign SPK A via direct POST
        $this->actingAs($managerB)
            ->post(route('admin.spk.approve', $spkA))
            ->assertStatus(404);

        // Manager B cannot reject foreign SPK A via direct POST
        $this->actingAs($managerB)
            ->post(route('admin.spk.reject', $spkA), ['reason' => 'Cross tenant rejection attempt'])
            ->assertStatus(404);

        $this->assertDatabaseHas('work_orders', [
            'id' => $spkA->id,
            'status' => 'waiting_review',
        ]);
    }

    public function test_canonical_approve_flow_side_effects_and_inbox_removal(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $spk = $this->waitingSpk($company, $creator);

        // Initially in inbox
        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $spk->id)
                ->where('items.data.0.actions.approve', true)
                ->where('summary.waiting_for_me', 1)
                ->where('summary.processed', 0)
            );

        // Execute canonical approve
        $this->actingAs($manager)
            ->post(route('admin.spk.approve', $spk))
            ->assertRedirect();

        $this->assertDatabaseHas('work_orders', [
            'id' => $spk->id,
            'status' => 'completed',
            'approved_by' => $manager->id,
        ]);

        $freshSpk = WorkOrder::withoutCompany()->findOrFail($spk->id);
        $this->assertNotNull($freshSpk->approved_at);

        // Re-visit inbox: item removed, summary updated
        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 0)
                ->where('summary.waiting_for_me', 0)
                ->where('summary.processed', 1)
            );
    }

    public function test_canonical_reject_flow_side_effects_and_inbox_removal(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $spk = $this->waitingSpk($company, $creator);

        // Initially in inbox
        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $spk->id)
                ->where('items.data.0.actions.reject', true)
                ->where('summary.waiting_for_me', 1)
                ->where('summary.processed', 0)
            );

        // Execute canonical reject with reason
        $this->actingAs($manager)
            ->post(route('admin.spk.reject', $spk), ['reason' => 'Dokumentasi bukti instalasi tidak lengkap'])
            ->assertRedirect();

        $this->assertDatabaseHas('work_orders', [
            'id' => $spk->id,
            'status' => 'rejected',
            'rejected_by' => $manager->id,
            'rejection_reason' => 'Dokumentasi bukti instalasi tidak lengkap',
        ]);

        $freshSpk = WorkOrder::withoutCompany()->findOrFail($spk->id);
        $this->assertNotNull($freshSpk->rejected_at);

        // Re-visit inbox: item removed, summary updated
        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 0)
                ->where('summary.waiting_for_me', 0)
                ->where('summary.processed', 1)
            );
    }

    public function test_reject_without_reason_fails_validation(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $spk = $this->waitingSpk($company, $creator);

        $this->actingAs($manager)
            ->post(route('admin.spk.reject', $spk), ['reason' => ''])
            ->assertSessionHasErrors(['reason']);

        $this->assertDatabaseHas('work_orders', [
            'id' => $spk->id,
            'status' => 'waiting_review',
        ]);
    }

    public function test_stale_or_double_action_prevention(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $spk = $this->waitingSpk($company, $creator);

        // First approval succeeds
        $this->actingAs($manager)
            ->post(route('admin.spk.approve', $spk))
            ->assertRedirect();

        $this->assertEquals('completed', $spk->fresh()->status);

        // Second approval attempt on already completed SPK fails with 422
        $this->actingAs($manager)
            ->post(route('admin.spk.approve', $spk))
            ->assertStatus(422);

        // Reject attempt on already completed SPK fails with 422
        $this->actingAs($manager)
            ->post(route('admin.spk.reject', $spk), ['reason' => 'Too late'])
            ->assertStatus(422);
    }

    public function test_no_batch_approval_route_exists(): void
    {
        $this->assertFalse(Route::has('admin.approvals.batch'));
        $this->assertFalse(Route::has('admin.approvals.bulk-approve'));
        $this->assertFalse(Route::has('admin.approvals.bulk-reject'));
    }

    public function test_badge_count_strictly_matches_summary_waiting_for_me(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $this->waitingSpk($company, $creator, ['code' => 'SPK-MATCH-01']);
        $this->waitingSpk($company, $creator, ['code' => 'SPK-MATCH-02']);
        $this->waitingSpk($company, $manager, ['code' => 'SPK-MATCH-03']); // self-created (excluded)

        $request = Request::create(route('admin.approvals.index'));
        $request->setUserResolver(fn () => $manager);

        $badge = ApprovalInboxQuery::badgeCount($request);
        $summary = ApprovalInboxQuery::summary($request);

        $this->assertNotNull($badge);
        $this->assertSame(2, $badge);
        $this->assertSame($badge, $summary['waiting_for_me']);
    }

    public function test_bounded_queries_no_n_plus_1(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // Create 5 waiting SPKs with items
        for ($i = 1; $i <= 5; $i++) {
            $this->waitingSpk($company, $creator, ['code' => "SPK-QUERY-0{$i}"]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 25 + 1: the shared props now list the company memberships of a user who may switch company;
        // + 1: the shared `canTechnicianReport` prop reads a manager's employee profile once per page.
        // + 1: the shared `company.map` prop reads the system map defaults in one query when the company set none.
        $this->assertLessThanOrEqual(28, $queryCount, "Expected bounded query count, got {$queryCount} queries.");
    }

    public function test_summary_contract_role_matrix_and_unapproved_domain_handling(): void
    {
        [$company, $manager] = $this->approver();
        $creator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // Create 1 eligible waiting SPK and 1 eligible processed SPK
        $this->waitingSpk($company, $creator, ['code' => 'SPK-SUM-001']);
        $this->waitingSpk($company, $creator, ['code' => 'SPK-SUM-002', 'status' => 'completed']);

        // 1. Technician: role matrix forbids unapproved domain / non-eligible actor from counting summary
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $technician->assignRole(Role::where('name', 'technician')->firstOrFail());

        $techRequest = Request::create(route('admin.approvals.index'));
        $techRequest->setUserResolver(fn () => $technician);
        $techSummary = ApprovalInboxQuery::summary($techRequest);

        $this->assertSame(0, $techSummary['waiting_for_me']);
        $this->assertSame(0, $techSummary['processed']);

        // 2. Approver with unapproved domain must return 0 for both summary counts and filtered list
        $unapprovedRequest = Request::create(route('admin.approvals.index', ['domain' => 'unapproved_domain']));
        $unapprovedRequest->setUserResolver(fn () => $manager);
        $unapprovedSummary = ApprovalInboxQuery::summary($unapprovedRequest);

        $this->assertSame(0, $unapprovedSummary['waiting_for_me']);
        $this->assertSame(0, $unapprovedSummary['processed']);
        $this->assertSame(0, ApprovalInboxQuery::filtered($unapprovedRequest)->count());

        // 3. Approver with domain=spk or no domain gets accurate counts per tenant
        $spkRequest = Request::create(route('admin.approvals.index', ['domain' => 'spk']));
        $spkRequest->setUserResolver(fn () => $manager);
        $spkSummary = ApprovalInboxQuery::summary($spkRequest);

        $this->assertSame(1, $spkSummary['waiting_for_me']);
        $this->assertSame(1, $spkSummary['processed']);
        $this->assertSame(1, ApprovalInboxQuery::filtered($spkRequest)->count());
    }

    private function approver(): array
    {
        $company = Company::factory()->create(['is_active' => true]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $manager->assignRole(Role::where('name', 'manager')->firstOrFail());

        return [$company, $manager];
    }

    private function waitingSpk(Company $company, ?User $creator, array $extra = []): WorkOrder
    {
        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-APPROVAL-'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'Approval Location',
            'type' => 'site',
            'path' => 'Approval',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        /** @var WorkOrder $workOrder */
        $workOrder = WorkOrderFactory::new()->create(array_merge([
            'company_id' => $company->id,
            'type' => 'maintenance',
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $creator?->id,
            'assigned_to' => null,
        ], $extra));

        $workOrder->addMediaFromString('test')
            ->usingFileName('test.jpg')
            ->withCustomProperties([
                'company_id' => $company->id,
                'type' => 'photo',
                'uploaded_by' => $creator->id,
            ])
            ->toMediaCollection('evidence', 'public');

        return $workOrder;
    }
}
