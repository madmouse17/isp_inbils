<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Rules\NoSodConflict;
use App\Rules\NoStackedSodConflict;
use App\Services\Core\CompanyService;
use App\Support\PermissionCatalogue;
use App\Support\SodConflicts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockAdjustmentRequest;
use Modules\Inventory\Services\StockService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Separation of duties per template (the persona matrix of docs/SECURITY.md §2.2) and the exemption that belongs to
 * roles.manage, the company administrator (ADR-004). The templates come with the migrations; each test works on a
 * company's own copies of them.
 */
class RolePersonaSodTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, Role> template key => the copy in a company made for the test */
    private function copies(): array
    {
        return $this->companyRoles(Company::factory()->create());
    }

    public function test_manager_template_does_not_hold_operator_permissions(): void
    {
        $manager = $this->copies()['manager'];

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

    public function test_staff_template_does_not_hold_controller_permissions(): void
    {
        $staff = $this->copies()['staff'];

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

    public function test_the_warehouse_and_finance_templates_stay_on_their_side_of_the_conflict_set(): void
    {
        $roles = $this->copies();

        // Warehouse executes, it never approves (OD4); Keuangan reverses a payment but cannot cancel an invoice.
        $this->assertTrue($roles['warehouse']->hasPermissionTo('inventory.request.issue'));
        $this->assertFalse($roles['warehouse']->hasPermissionTo('inventory.request.approve'));
        $this->assertFalse($roles['warehouse']->hasPermissionTo('inventory.stock.adjust.approve'));
        $this->assertTrue($roles['finance']->hasPermissionTo('billing.payment.reverse'));
        $this->assertFalse($roles['finance']->hasPermissionTo('billing.cancel'));
    }

    public function test_report_export_is_held_by_admin_and_manager_only(): void
    {
        foreach ($this->copies() as $key => $role) {
            $this->assertSame(in_array($key, ['admin', 'manager'], true), $role->hasPermissionTo('report.export'), $key);
        }
    }

    public function test_direct_stock_adjust_is_a_break_glass_permission_held_by_admin_only(): void
    {
        foreach ($this->copies() as $key => $role) {
            $this->assertSame($key === 'admin', $role->hasPermissionTo('inventory.stock.adjust'), $key);
        }
    }

    public function test_no_non_admin_template_breaks_the_declared_conflict_set(): void
    {
        foreach ($this->copies() as $key => $role) {
            if ($key === 'admin') {
                continue;
            }

            $this->assertSame([], SodConflicts::violations($role->permissions()->pluck('name')->all()), $key);
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

    public function test_conflict_messages_name_the_rule_and_both_sides_in_indonesian(): void
    {
        $this->assertSame(
            ['Pemisahan tugas (persetujuan penyesuaian stok vs operasi stok): inventory.stock.adjust.approve tidak boleh digabung dengan inventory.stock.adjust.request, inventory.stock.receive.'],
            SodConflicts::violations(['inventory.stock.adjust.approve', 'inventory.stock.adjust.request', 'inventory.stock.receive']),
        );
    }

    public function test_only_a_set_holding_roles_manage_is_exempt_from_the_conflict_set(): void
    {
        $conflicting = ['inventory.request.approve', 'inventory.request.issue'];
        $check = fn (array $permissions, bool $exempt): bool => Validator::make(['permissions' => $permissions], ['permissions' => ['array', new NoSodConflict(exempt: $exempt)]])->passes();

        $this->assertFalse($check($conflicting, false));
        $this->assertTrue($check($conflicting, true), 'the requests pass exempt: true for a set that holds roles.manage');
        $this->assertTrue($check(['inventory.request.approve'], false));
    }

    public function test_only_an_actor_holding_roles_manage_may_stack_roles_that_conflict(): void
    {
        $company = Company::factory()->create();
        $roles = $this->companyRoles($company);
        $approver = $this->companyRole($company, 'Penyetuju', ['inventory.request.approve']);
        $preparer = $this->companyRole($company, 'Penyiap', ['inventory.request.prepare']);
        $admin = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'admin', $company);
        $manager = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'manager', $company);
        $passes = fn (?User $actor): bool => CompanyService::runAs($company->id, fn (): bool => Validator::make(
            ['roles' => [$approver->name, $preparer->name]],
            ['roles' => ['array', new NoStackedSodConflict($actor)]],
        )->passes());

        $this->assertTrue($passes($admin));
        $this->assertFalse($passes($manager));
        $this->assertFalse($passes(null));
        $this->assertNotEmpty($roles);
    }

    public function test_invoice_creator_cannot_cancel_own_invoice(): void
    {
        $company = Company::factory()->create();
        $creator = User::factory()->create(['company_id' => $company->id]);
        $this->grantRole($creator, 'manager', $company);

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
        $this->expectExceptionMessage('Tagihan yang Anda buat sendiri tidak bisa Anda batalkan.');
        BillingService::cancel($invoice, 'Self cancellation attempt');
    }

    public function test_different_manager_can_cancel_invoice(): void
    {
        $company = Company::factory()->create();
        $creator = User::factory()->create(['company_id' => $company->id]);
        $this->grantRole($creator, 'manager', $company);

        $canceller = User::factory()->create(['company_id' => $company->id]);
        $this->grantRole($canceller, 'manager', $company);
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
        $this->expectExceptionMessage('Maker-checker: pemohon tidak boleh menyetujui permintaan penyesuaian stoknya sendiri.');
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

    public function test_a_manager_cannot_give_a_user_the_admin_role(): void
    {
        [$company, $manager, $roles] = $this->managerWorld();
        $target = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($manager)->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => [$roles['admin']->name],
        ])->assertSessionHasErrors('roles');

        $this->assertSame([], $this->roleNamesIn($company, $target));
    }

    public function test_a_role_with_a_permission_the_actor_lacks_cannot_be_given(): void
    {
        [$company, $manager] = $this->managerWorld();
        $network = $this->companyRole($company, 'Pengelola Jaringan', ['network_asset.delete']);
        $target = User::factory()->create(['company_id' => $company->id]);

        $this->assertFalse($manager->can('network_asset.delete'));

        $this->actingAs($manager)->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => [$network->name],
        ])->assertSessionHasErrors('roles');

        $this->assertSame([], $this->roleNamesIn($company, $target));
    }

    public function test_a_manager_cannot_update_the_roles_of_the_company(): void
    {
        [$company, $manager, $roles] = $this->managerWorld();

        $this->actingAs($manager);

        $this->assertFalse((new RolePolicy())->update($manager, $roles['manager']));
        $this->assertFalse($manager->can('update', $roles['manager']));
        $this->assertSame(['Manajer'], $this->roleNamesIn($company, $manager));
    }

    public function test_a_manager_cannot_create_a_user_with_the_admin_role(): void
    {
        [, $manager, $roles] = $this->managerWorld();

        $this->actingAs($manager)
            ->post(route('admin.users.store'), [
                'name' => 'Sneaky Admin',
                'email' => 'sneaky-admin@example.test',
                'password' => 'password-123',
                'roles' => [$roles['admin']->name],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertFalse(User::query()->where('email', 'sneaky-admin@example.test')->exists());
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
        $admin = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'admin', $company);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $bystander = User::factory()->create(['company_id' => $company->id]);
        $this->adjustmentRequest($company, $requester, $approver);

        foreach ([$requester, $approver] as $actor) {
            $this->actingAs($admin)
                ->delete(route('admin.users.destroy', $actor))
                ->assertSessionHasErrors(['user' => 'Pengguna yang pernah mengajukan atau memeriksa penyesuaian stok tidak bisa dihapus. Nonaktifkan akunnya.']);
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
            ->assertSessionHasErrors(['user' => 'Pengguna yang pernah mengajukan atau memeriksa penyesuaian stok tidak bisa dihapus. Nonaktifkan akunnya.']);

        $this->assertTrue(User::query()->whereKey($requester->id)->exists());
        $this->assertAuthenticatedAs($requester);
    }

    public function test_a_manager_cannot_edit_update_or_delete_an_administrator(): void
    {
        [$company, $manager, $roles] = $this->managerWorld();
        $admin = $this->grantRole(User::factory()->create(['company_id' => $company->id, 'email' => 'root-admin@example.test']), 'admin', $company);
        $originalPassword = $admin->password;

        $this->actingAs($manager)->get(route('admin.users.edit', $admin))->assertForbidden();
        $this->actingAs($manager)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => 'hijacked@example.test',
                'password' => 'new-password-123',
                'roles' => [$roles['staff']->name],
            ])
            ->assertForbidden();
        $this->actingAs($manager)->delete(route('admin.users.destroy', $admin))->assertForbidden();

        $admin->refresh();
        $this->assertSame('root-admin@example.test', $admin->email);
        $this->assertSame($originalPassword, $admin->password);
        $this->assertSame(['Admin'], $this->roleNamesIn($company, $admin));
    }

    public function test_user_management_stays_open_for_users_the_actor_covers_and_for_administrators(): void
    {
        [$company, $manager] = $this->managerWorld();
        $reader = $this->companyRole($company, 'Pembaca', ['customer.view']);
        $covered = $this->grantRole(User::factory()->create(['company_id' => $company->id]), $reader, $company);
        $admin = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'admin', $company);
        $otherAdmin = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'admin', $company);

        $this->actingAs($manager)
            ->put(route('admin.users.update', $covered), ['name' => 'Renamed Reader', 'email' => $covered->email, 'roles' => [$reader->name]])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame('Renamed Reader', $covered->fresh()->name);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $otherAdmin), ['name' => 'Renamed Admin', 'email' => $otherAdmin->email, 'roles' => ['Admin']])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame('Renamed Admin', $otherAdmin->fresh()->name);
    }

    public function test_an_actor_without_roles_manage_cannot_stack_manager_and_staff_roles_on_one_user(): void
    {
        $company = Company::factory()->create();
        $roles = $this->companyRoles($company);
        // He holds every permission of both roles directly (so the hold-it-yourself guard lets him give them), yet he is no
        // administrator: stacking the two would merge the control and the execution side.
        $templates = collect(PermissionCatalogue::templates())->keyBy('key');
        $actor = User::factory()->create(['company_id' => $company->id]);
        $actor->givePermissionTo(array_values(array_unique([...$templates['manager']['permissions'], ...$templates['staff']['permissions'], 'users.manage', 'app.web'])));
        $target = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'staff', $company);

        $this->actingAs($actor)
            ->post(route('admin.users.store'), [
                'name' => 'Stacked User',
                'email' => 'stacked@example.test',
                'password' => 'password-123',
                'roles' => [$roles['manager']->name, $roles['staff']->name],
            ])
            ->assertSessionHasErrors('roles');
        $this->assertFalse(User::query()->where('email', 'stacked@example.test')->exists());

        $this->actingAs($actor)
            ->put(route('admin.users.update', $target), ['name' => $target->name, 'email' => $target->email, 'roles' => [$roles['manager']->name, $roles['staff']->name]])
            ->assertSessionHasErrors('roles');
        $this->assertSame(['Staf'], $this->roleNamesIn($company, $target));

        $this->actingAs($actor)
            ->put(route('admin.users.update', $target), ['name' => $target->name, 'email' => $target->email, 'roles' => [$roles['manager']->name]])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame(['Manajer'], $this->roleNamesIn($company, $target));
    }

    public function test_admin_may_stack_roles_as_break_glass(): void
    {
        $company = Company::factory()->create();
        $roles = $this->companyRoles($company);
        $admin = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'admin', $company);
        $target = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), ['name' => $target->name, 'email' => $target->email, 'roles' => [$roles['manager']->name, $roles['staff']->name]])
            ->assertRedirect(route('admin.users.index'));

        $this->assertEqualsCanonicalizing(['Manajer', 'Staf'], $this->roleNamesIn($company, $target));
    }

    /**
     * A company, and a manager in it who may manage users (the Manajer template holds neither users.manage nor
     * roles.manage; the permission is given to him directly, as a custom grant would).
     *
     * @return array{0: Company, 1: User, 2: array<string, Role>}
     */
    private function managerWorld(): array
    {
        $company = Company::factory()->create();
        $roles = $this->companyRoles($company);
        $manager = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'manager', $company);
        $manager->givePermissionTo('users.manage');

        return [$company, $manager, $roles];
    }

    /** @return list<string> the names of the roles the user holds in the company, sorted */
    private function roleNamesIn(Company $company, User $user): array
    {
        $names = CompanyService::runAs($company->id, fn (): array => User::query()->findOrFail($user->id)->getRoleNames()->all());
        sort($names);

        return $names;
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
