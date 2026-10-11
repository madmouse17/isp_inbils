<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\SodConflicts;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockAdjustmentRequest;
use Modules\Inventory\Services\StockService;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RolePersonaSodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_seeded_manager_does_not_hold_operator_permissions(): void
    {
        $manager = Role::findByName('manager');

        $this->assertFalse($manager->hasPermissionTo('inventory.stock.adjust'));
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.adjust.request'));
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.receive'));
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.issue'));
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.transfer'));
        $this->assertFalse($manager->hasPermissionTo('inventory.request.prepare'));
        $this->assertFalse($manager->hasPermissionTo('inventory.request.issue'));

        $this->assertTrue($manager->hasPermissionTo('inventory.stock.adjust.approve'));
        $this->assertTrue($manager->hasPermissionTo('inventory.request.approve'));
        $this->assertTrue($manager->hasPermissionTo('inventory.request.reject'));
    }

    public function test_seeded_staff_does_not_hold_controller_permissions(): void
    {
        $staff = Role::findByName('staff');

        $this->assertFalse($staff->hasPermissionTo('inventory.request.approve'));
        $this->assertFalse($staff->hasPermissionTo('inventory.request.reject'));
        $this->assertFalse($staff->hasPermissionTo('inventory.stock.adjust.approve'));
        $this->assertFalse($staff->hasPermissionTo('spk.approve'));
        $this->assertFalse($staff->hasPermissionTo('spk.reject'));
        $this->assertFalse($staff->hasPermissionTo('billing.payment.reverse'));
        $this->assertFalse($staff->hasPermissionTo('billing.cancel'));

        $this->assertTrue($staff->hasPermissionTo('inventory.stock.adjust.request'));
        $this->assertTrue($staff->hasPermissionTo('inventory.request.prepare'));
        $this->assertTrue($staff->hasPermissionTo('inventory.request.issue'));
    }

    public function test_report_export_is_held_by_admin_and_manager_only(): void
    {
        foreach (['admin' => true, 'manager' => true, 'staff' => false, 'technician' => false, 'customer' => false] as $role => $holds) {
            $this->assertSame($holds, Role::findByName($role)->hasPermissionTo('report.export'), $role);
        }
    }

    public function test_direct_stock_adjust_is_a_break_glass_permission_held_by_admin_only(): void
    {
        foreach (['admin' => true, 'manager' => false, 'staff' => false, 'technician' => false, 'customer' => false] as $role => $holds) {
            $this->assertSame($holds, Role::findByName($role)->hasPermissionTo('inventory.stock.adjust'), $role);
        }
    }

    public function test_no_seeded_non_admin_role_breaks_the_declared_conflict_set(): void
    {
        foreach (['manager', 'staff', 'technician', 'customer'] as $name) {
            $permissions = Role::findByName($name)->permissions()->pluck('name')->all();

            $this->assertSame([], SodConflicts::violations($permissions), $name);
        }
    }

    public function test_conflict_set_flags_each_rule_and_the_admin_only_permission(): void
    {
        $this->assertCount(1, SodConflicts::violations(['inventory.request.approve', 'inventory.request.issue']));
        $this->assertCount(1, SodConflicts::violations(['inventory.stock.adjust.approve', 'inventory.stock.adjust.request']));
        $this->assertCount(2, SodConflicts::violations(['inventory.request.reject', 'inventory.stock.receive', 'inventory.stock.adjust.approve']));
        $this->assertCount(1, SodConflicts::violations(['inventory.stock.adjust']));
        $this->assertSame([], SodConflicts::violations(['inventory.request.approve', 'inventory.stock.adjust.approve']));
        $this->assertSame([], SodConflicts::violations(['inventory.request.prepare', 'inventory.request.issue', 'inventory.stock.receive']));
    }

    public function test_reseeding_narrows_seeded_roles_and_leaves_custom_roles_untouched(): void
    {
        Role::findByName('manager')->givePermissionTo(['inventory.stock.receive', 'inventory.stock.adjust', 'inventory.request.prepare']);
        Role::findByName('staff')->givePermissionTo(['inventory.request.approve', 'spk.approve']);
        $custom = Role::create(['name' => 'custom_clerk', 'guard_name' => 'web']);
        $custom->givePermissionTo(['inventory.stock.receive', 'inventory.request.approve']);

        $this->seed(RolePermissionSeeder::class);

        $manager = Role::findByName('manager');
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.receive'));
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.adjust'));
        $this->assertFalse($manager->hasPermissionTo('inventory.request.prepare'));
        $this->assertTrue($manager->hasPermissionTo('inventory.stock.adjust.approve'));

        $staff = Role::findByName('staff');
        $this->assertFalse($staff->hasPermissionTo('inventory.request.approve'));
        $this->assertFalse($staff->hasPermissionTo('spk.approve'));

        $this->assertEqualsCanonicalizing(
            ['inventory.stock.receive', 'inventory.request.approve'],
            Role::findByName('custom_clerk')->permissions()->pluck('name')->all(),
        );
    }

    public function test_invoice_creator_cannot_cancel_own_invoice(): void
    {
        $company = Company::factory()->create();
        $creator = User::factory()->create(['company_id' => $company->id]);
        $creator->assignRole('manager');

        $canceller = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'number' => 'INV-SOD-001',
            'type' => 'one_time',
            'source' => 'manual',
            'customer_id' => $customer->id,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'status' => 'sent',
            'total' => '100000.00',
            'subtotal' => '100000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'paid_amount' => '0.00',
            'created_by' => $creator->id,
        ]);

        Auth::login($creator);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Cannot cancel own created invoice.');
        BillingService::cancel($invoice, 'Self cancellation attempt');
    }

    public function test_different_manager_can_cancel_invoice(): void
    {
        $company = Company::factory()->create();
        $creator = User::factory()->create(['company_id' => $company->id]);
        $creator->assignRole('manager');

        $canceller = User::factory()->create(['company_id' => $company->id]);
        $canceller->assignRole('manager');
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'number' => 'INV-SOD-002',
            'type' => 'one_time',
            'source' => 'manual',
            'customer_id' => $customer->id,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'status' => 'sent',
            'total' => '100000.00',
            'subtotal' => '100000.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'paid_amount' => '0.00',
            'created_by' => $creator->id,
        ]);

        Auth::login($canceller);

        $cancelled = BillingService::cancel($invoice, 'Legitimate cancellation');
        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertEquals($canceller->id, $cancelled->cancelled_by);
    }

    public function test_stock_adjustment_requester_cannot_approve_own_request(): void
    {
        $company = Company::factory()->create();
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);

        $product = Product::factory()->create(['company_id' => $company->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        Auth::login($requester);

        $request = StockService::requestAdjustment($product->id, $location->id, 15, 'Cycle count deficiency');
        $this->assertEquals('pending', $request->status);

        // Self-approval must fail
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Maker-checker: requester cannot approve own stock adjustment request.');
        StockService::approveAdjustment($request, $requester->id);
    }

    public function test_different_user_can_approve_stock_adjustment_request(): void
    {
        $company = Company::factory()->create();
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);

        $product = Product::factory()->create(['company_id' => $company->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        Auth::login($requester);
        $request = StockService::requestAdjustment($product->id, $location->id, 15, 'Cycle count deficiency');

        Auth::login($approver);
        $movement = StockService::approveAdjustment($request, $approver->id);

        $this->assertEquals(5, $movement->quantity);
        $this->assertEquals('approved', $request->fresh()->status);
        $this->assertEquals($approver->id, $request->fresh()->approved_by);
    }

    public function test_non_admin_cannot_assign_admin_role(): void
    {
        $company = Company::factory()->create();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('users.manage');

        $target = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($manager)->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['admin'],
        ]);

        $response->assertSessionHasErrors('roles');
        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_non_admin_cannot_update_protected_roles(): void
    {
        $company = Company::factory()->create();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');

        $managerRole = Role::findByName('manager');

        $this->actingAs($manager);
        $policy = new RolePolicy();
        $this->assertFalse($policy->update($manager, $managerRole));
    }

    public function test_non_admin_cannot_create_a_user_with_the_admin_role(): void
    {
        $company = Company::factory()->create();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('users.manage');

        $this->actingAs($manager)
            ->post(route('admin.users.store'), [
                'name' => 'Sneaky Admin',
                'email' => 'sneaky-admin@example.test',
                'password' => 'password-123',
                'roles' => ['admin'],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertFalse(User::query()->where('email', 'sneaky-admin@example.test')->exists());
    }

    public function test_non_admin_cannot_save_a_role_that_combines_conflicting_duties(): void
    {
        $actor = $this->roleManager();

        $this->actingAs($actor)
            ->post(route('admin.roles.store'), [
                'name' => 'warehouse_super',
                'permissions' => ['inventory.request.approve', 'inventory.request.prepare'],
            ])
            ->assertSessionHasErrors('permissions');
        $this->actingAs($actor)
            ->post(route('admin.roles.store'), [
                'name' => 'break_glass_clone',
                'permissions' => ['inventory.stock.adjust'],
            ])
            ->assertSessionHasErrors('permissions');
        $this->assertSame(0, Role::query()->whereIn('name', ['warehouse_super', 'break_glass_clone'])->count());

        $this->actingAs($actor)
            ->post(route('admin.roles.store'), [
                'name' => 'warehouse_ops',
                'permissions' => ['inventory.request.prepare', 'inventory.request.issue'],
            ])
            ->assertRedirect(route('admin.roles.index'));
        $this->assertTrue(Role::query()->where('name', 'warehouse_ops')->exists());
    }

    public function test_non_admin_cannot_add_a_conflict_to_an_existing_custom_role(): void
    {
        $actor = $this->roleManager();
        $custom = Role::create(['name' => 'warehouse_ops', 'guard_name' => 'web']);
        $custom->givePermissionTo('inventory.request.prepare');

        $this->actingAs($actor)
            ->put(route('admin.roles.update', $custom), [
                'name' => 'warehouse_ops',
                'permissions' => ['inventory.request.prepare', 'inventory.request.approve'],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertEqualsCanonicalizing(
            ['inventory.request.prepare'],
            Role::findByName('warehouse_ops')->permissions()->pluck('name')->all(),
        );
    }

    public function test_malformed_permission_list_is_a_validation_error_not_a_crash(): void
    {
        $actor = $this->roleManager();

        $this->actingAs($actor)
            ->post(route('admin.roles.store'), [
                'name' => 'malformed_role',
                'permissions' => [['inventory.request.approve'], 'inventory.request.prepare'],
            ])
            ->assertSessionHasErrors('permissions.0');

        $this->assertFalse(Role::query()->where('name', 'malformed_role')->exists());
    }

    public function test_admin_may_save_a_conflicting_role_as_break_glass(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.roles.store'), [
                'name' => 'break_glass_role',
                'permissions' => ['inventory.request.approve', 'inventory.request.prepare', 'inventory.stock.adjust'],
            ])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertTrue(Role::findByName('break_glass_role')->hasPermissionTo('inventory.stock.adjust'));
    }

    public function test_adjustment_actor_foreign_keys_restrict_user_deletion_to_keep_evidence(): void
    {
        $rules = [];
        foreach (Schema::getForeignKeys((new StockAdjustmentRequest())->getTable()) as $foreignKey) {
            if ($foreignKey['foreign_table'] === 'users') {
                $rules[implode(',', $foreignKey['columns'])] = $foreignKey['on_delete'];
            }
        }
        ksort($rules);

        $this->assertSame(['approved_by' => 'restrict', 'requested_by' => 'restrict'], $rules);
    }

    public function test_user_who_requested_or_approved_an_adjustment_cannot_be_deleted(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('admin');
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $bystander = User::factory()->create(['company_id' => $company->id]);
        $this->adjustmentRequest($company, $requester, $approver);

        foreach ([$requester, $approver] as $actor) {
            $this->actingAs($admin)
                ->delete(route('admin.users.destroy', $actor))
                ->assertSessionHasErrors('user');
            $this->assertTrue(User::query()->whereKey($actor->id)->exists());
        }

        $this->actingAs($admin)->delete(route('admin.users.destroy', $bystander))->assertRedirect();
        $this->assertFalse(User::query()->whereKey($bystander->id)->exists());
    }

    public function test_user_who_requested_an_adjustment_cannot_delete_their_own_account(): void
    {
        $company = Company::factory()->create();
        $requester = User::factory()->create(['company_id' => $company->id]);
        $this->adjustmentRequest($company, $requester);

        $this->actingAs($requester)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('user');

        $this->assertTrue(User::query()->whereKey($requester->id)->exists());
        $this->assertAuthenticatedAs($requester);
    }

    public function test_non_admin_cannot_edit_update_or_delete_an_admin_user(): void
    {
        $company = Company::factory()->create();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('users.manage');
        $admin = User::factory()->create(['company_id' => $company->id, 'email' => 'root-admin@example.test']);
        $admin->assignRole('admin');
        $originalPassword = $admin->password;

        $this->actingAs($manager)->get(route('admin.users.edit', $admin))->assertForbidden();
        $this->actingAs($manager)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => 'hijacked@example.test',
                'password' => 'new-password-123',
                'roles' => ['staff'],
            ])
            ->assertForbidden();
        $this->actingAs($manager)->delete(route('admin.users.destroy', $admin))->assertForbidden();

        $admin->refresh();
        $this->assertSame('root-admin@example.test', $admin->email);
        $this->assertSame($originalPassword, $admin->password);
        $this->assertTrue($admin->hasRole('admin'));
    }

    public function test_user_management_stays_open_for_non_admin_targets_and_for_admin_actors(): void
    {
        $company = Company::factory()->create();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('users.manage');
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('admin');
        $staff = User::factory()->create(['company_id' => $company->id]);
        $staff->assignRole('staff');
        $otherAdmin = User::factory()->create(['company_id' => $company->id]);
        $otherAdmin->assignRole('admin');

        $this->actingAs($manager)
            ->put(route('admin.users.update', $staff), ['name' => 'Renamed Staff', 'email' => $staff->email, 'roles' => ['staff']])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame('Renamed Staff', $staff->fresh()->name);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $otherAdmin), ['name' => 'Renamed Admin', 'email' => $otherAdmin->email, 'roles' => ['admin']])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame('Renamed Admin', $otherAdmin->fresh()->name);
    }

    public function test_non_admin_cannot_stack_manager_and_staff_roles_on_one_user(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->givePermissionTo('users.manage');
        $target = User::factory()->create(['company_id' => $company->id]);
        $target->assignRole('staff');

        $this->actingAs($actor)
            ->post(route('admin.users.store'), [
                'name' => 'Stacked User',
                'email' => 'stacked@example.test',
                'password' => 'password-123',
                'roles' => ['manager', 'staff'],
            ])
            ->assertSessionHasErrors('roles');
        $this->assertFalse(User::query()->where('email', 'stacked@example.test')->exists());

        $this->actingAs($actor)
            ->put(route('admin.users.update', $target), ['name' => $target->name, 'email' => $target->email, 'roles' => ['manager', 'staff']])
            ->assertSessionHasErrors('roles');
        $this->assertSame(['staff'], $target->fresh()->getRoleNames()->all());

        $this->actingAs($actor)
            ->put(route('admin.users.update', $target), ['name' => $target->name, 'email' => $target->email, 'roles' => ['manager']])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame(['manager'], $target->fresh()->getRoleNames()->all());
    }

    public function test_admin_may_stack_roles_as_break_glass(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('admin');
        $target = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), ['name' => $target->name, 'email' => $target->email, 'roles' => ['manager', 'staff']])
            ->assertRedirect(route('admin.users.index'));

        $this->assertEqualsCanonicalizing(['manager', 'staff'], $target->fresh()->getRoleNames()->all());
    }

    private function roleManager(): User
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->givePermissionTo('roles.manage');

        return $actor;
    }

    private function adjustmentRequest(Company $company, User $requester, ?User $approver = null): StockAdjustmentRequest
    {
        return StockAdjustmentRequest::withoutCompany()->create([
            'company_id' => $company->id,
            'product_id' => Product::factory()->create(['company_id' => $company->id])->id,
            'location_id' => Location::factory()->create(['company_id' => $company->id])->id,
            'old_quantity' => 10,
            'new_quantity' => 4,
            'delta' => -6,
            'reason' => 'cycle count',
            'status' => $approver ? 'approved' : 'pending',
            'requested_by' => $requester->id,
            'approved_by' => $approver?->id,
            'approved_at' => $approver ? now() : null,
        ]);
    }
}
