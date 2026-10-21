<?php

namespace Tests\Feature\Roles;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A company's roles are written by whoever holds roles.manage there (ADR-004), within the guards of
 * CompanyRoleService: nobody grants a permission he lacks (G1), edits a role richer than himself (G2), breaks the
 * separation of duties (G3, G4) or takes away the last administrator (G5); the role of customer accounts stays (G6);
 * a role with users is deleted only after they moved (G7); a stale edit is refused (G8).
 */
class TenantRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    /** @var array<string, Role> template key => the company's copy */
    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->company = Company::factory()->create(['is_active' => true]);
        $this->roles = $this->companyRoles($this->company);
    }

    public function test_role_managers_list_only_their_company_roles(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $this->companyRoles($other);
        $this->companyRole($other, 'Peran Perusahaan Lain', ['ticket.view']);
        $this->companyRole($this->company, 'Peran Sendiri', ['ticket.view']);
        $total = Role::withoutCompany()->where('company_id', $this->company->id)->count();

        $this->actingAs($this->holder('admin'))
            ->get(route('admin.roles.index', ['per_page' => 50]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roles/Index')
                ->has('roles.data', $total)
                ->where('roles.data', fn ($rows): bool => collect($rows)->pluck('name')->contains('Peran Sendiri')
                    && collect($rows)->pluck('name')->doesntContain('Peran Perusahaan Lain'))
                ->where('roles.data', fn ($rows): bool => collect($rows)->firstWhere('template_key', 'admin')['can'] === ['update' => true, 'delete' => true])
                ->where('moveTargets', fn ($targets): bool => count($targets) === $total
                    && collect($targets)->pluck('name')->doesntContain('Peran Perusahaan Lain'))
                ->has('templateNames.admin')
                ->where('can', ['create' => true, 'export' => true]));
    }

    public function test_create_rename_and_edit_are_audited_with_the_permission_diff(): void
    {
        $admin = $this->holder('admin');
        Activity::query()->delete();

        $this->actingAs($admin)
            ->post(route('admin.roles.store'), ['name' => 'Kasir', 'permissions' => ['billing.view', 'billing.payment.record']])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHas('success', 'Peran dibuat.');

        $role = $this->role('Kasir');
        $this->assertNull($role->template_key, 'a role the company makes has no template');
        $this->assertSame($this->company->id, (int) $role->company_id);
        $this->assertSame(['billing.payment.record', 'billing.view'], $this->permissionsOf($role));

        $created = Activity::query()->where('description', 'role.created')->sole();
        $this->assertSame('security', $created->log_name);
        $this->assertSame([Role::class, $role->id, $this->company->id, $admin->id], [$created->subject_type, $created->subject_id, (int) $created->company_id, $created->causer_id]);
        $this->assertEquals(['name' => 'Kasir', 'permissions' => ['billing.payment.record', 'billing.view']], $created->properties->all());

        $this->put(route('admin.roles.update', $role), [
            'name' => 'Kasir Utama',
            'permissions' => ['billing.view', 'billing.send'],
            OptimisticLock::FIELD => OptimisticLock::version($role->fresh()),
        ])->assertRedirect(route('admin.roles.index'))->assertSessionHas('success', 'Peran diperbarui.');

        $this->assertSame('Kasir Utama', $role->fresh()->name);
        $this->assertSame(['billing.send', 'billing.view'], $this->permissionsOf($role));

        $updated = Activity::query()->where('description', 'role.updated')->sole();
        $this->assertSame('security', $updated->log_name);
        $this->assertSame([Role::class, $role->id, $this->company->id, $admin->id], [$updated->subject_type, $updated->subject_id, (int) $updated->company_id, $updated->causer_id]);
        $this->assertEquals([
            'name_before' => 'Kasir',
            'name_after' => 'Kasir Utama',
            'permissions_added' => ['billing.send'],
            'permissions_removed' => ['billing.payment.record'],
        ], $updated->properties->all());

        // A save that changes nothing writes nothing.
        $this->put(route('admin.roles.update', $role), [
            'name' => 'Kasir Utama',
            'permissions' => ['billing.send', 'billing.view'],
            OptimisticLock::FIELD => OptimisticLock::version($role->fresh()),
        ])->assertRedirect(route('admin.roles.index'));

        $this->assertSame(1, Activity::query()->where('description', 'role.updated')->count());
    }

    public function test_a_permission_the_actor_lacks_cannot_be_granted(): void
    {
        $lead = $this->holderOf(['app.web', 'roles.manage', 'ticket.view']);
        $this->actingAs($lead);

        $this->post(route('admin.roles.store'), ['name' => 'Pembagi', 'permissions' => ['ticket.view', 'billing.update']])
            ->assertSessionHasErrors(['permissions' => 'Anda tidak dapat memberi hak akses yang tidak Anda miliki: billing.update.']);
        $this->assertFalse(Role::withoutCompany()->where('name', 'Pembagi')->exists());

        // The markers classify a person and open nothing alone: whoever edits roles may place them.
        $this->post(route('admin.roles.store'), ['name' => 'Penanda', 'permissions' => ['ticket.view', 'evaluation.evaluable']])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame(['evaluation.evaluable', 'ticket.view'], $this->permissionsOf($this->role('Penanda')));

        $basic = $this->companyRole($this->company, 'Dasar', ['ticket.view']);
        $this->put(route('admin.roles.update', $basic), [
            'name' => 'Dasar',
            'permissions' => ['ticket.view', 'billing.update'],
            OptimisticLock::FIELD => OptimisticLock::version($basic->fresh()),
        ])->assertSessionHasErrors(['permissions' => 'Anda tidak dapat memberi hak akses yang tidak Anda miliki: billing.update.']);
        $this->assertSame(['ticket.view'], $this->permissionsOf($basic));

        // Taking permissions away needs no holding.
        $this->put(route('admin.roles.update', $basic), [
            'name' => 'Dasar',
            'permissions' => [],
            OptimisticLock::FIELD => OptimisticLock::version($basic->fresh()),
        ])->assertRedirect(route('admin.roles.index'));
        $this->assertSame([], $this->permissionsOf($basic));
    }

    public function test_a_role_richer_than_the_actor_cannot_be_edited_or_deleted(): void
    {
        $lead = $this->holderOf(['app.web', 'roles.manage', 'ticket.view']);
        $rich = $this->companyRole($this->company, 'Kasir', ['billing.update', 'ticket.view']);
        $equal = $this->companyRole($this->company, 'Setara', ['ticket.view']);
        $this->actingAs($lead);

        foreach ([$rich, $this->roles['admin']] as $role) {
            $this->get(route('admin.roles.edit', $role))->assertForbidden();
            $this->put(route('admin.roles.update', $role), [
                'name' => 'Diubah',
                'permissions' => ['ticket.view'],
                OptimisticLock::FIELD => OptimisticLock::version($role->fresh()),
            ])->assertForbidden();
            $this->delete(route('admin.roles.destroy', $role))->assertForbidden();

            $this->assertTrue(Role::withoutCompany()->whereKey($role->id)->where('name', $role->name)->exists(), "{$role->name} is untouched");
        }

        $this->get(route('admin.roles.edit', $equal))->assertOk();
        $this->get(route('admin.roles.index', ['per_page' => 50]))
            ->assertInertia(fn (Assert $page) => $page->where('roles.data', fn ($rows): bool => collect($rows)->firstWhere('name', 'Kasir')['can'] === ['update' => false, 'delete' => false]
                && collect($rows)->firstWhere('name', 'Setara')['can'] === ['update' => true, 'delete' => true]));
    }

    public function test_separation_of_duties_is_enforced_except_for_roles_holding_roles_manage(): void
    {
        $this->actingAs($this->holder('admin'));
        $conflict = ['inventory.request.approve', 'inventory.request.issue'];

        $this->post(route('admin.roles.store'), ['name' => 'Campuran', 'permissions' => $conflict])
            ->assertSessionHasErrors('permissions');
        $this->assertFalse(Role::withoutCompany()->where('name', 'Campuran')->exists());

        $this->post(route('admin.roles.store'), ['name' => 'Pemilik', 'permissions' => [...$conflict, 'roles.manage']])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame(['inventory.request.approve', 'inventory.request.issue', 'roles.manage'], $this->permissionsOf($this->role('Pemilik')));

        $clean = $this->companyRole($this->company, 'Bersih', ['inventory.request.approve']);
        $this->put(route('admin.roles.update', $clean), [
            'name' => 'Bersih',
            'permissions' => $conflict,
            OptimisticLock::FIELD => OptimisticLock::version($clean->fresh()),
        ])->assertSessionHasErrors('permissions');
        $this->assertSame(['inventory.request.approve'], $this->permissionsOf($clean));
    }

    public function test_a_role_with_users_needs_a_move_target_and_moves_them(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = $this->companyRole($other, 'Peran Asing', ['inventory.view']);
        $old = $this->companyRole($this->company, 'Gudang Lama', ['inventory.view']);
        $new = $this->companyRole($this->company, 'Gudang Baru', ['inventory.view', 'inventory.create']);
        $unused = $this->companyRole($this->company, 'Tanpa Pengguna', ['inventory.view']);
        $first = $this->newUser($this->company);
        $second = $this->newUser($this->company);
        $this->grantRole($first, $old, $this->company);
        $this->grantRole($first, $new, $this->company);
        $this->grantRole($second, $old, $this->company);

        $this->actingAs($this->holder('admin'));
        Activity::query()->delete();

        $this->delete(route('admin.roles.destroy', $old))
            ->assertSessionHasErrors(['move_to' => 'Pilih peran tujuan untuk memindahkan pengguna.']);
        $this->delete(route('admin.roles.destroy', $old), ['move_to' => $old->public_id])->assertSessionHasErrors('move_to');
        $this->delete(route('admin.roles.destroy', $old), ['move_to' => $foreign->public_id])->assertSessionHasErrors('move_to');
        $this->assertTrue(Role::withoutCompany()->whereKey($old->id)->exists());
        $this->assertSame([], Activity::query()->where('description', 'like', 'role.%')->pluck('description')->all(), 'a refused delete leaves no trace');

        $this->delete(route('admin.roles.destroy', $old), ['move_to' => $new->public_id])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHas('success', 'Peran dihapus.');

        $this->assertFalse(Role::withoutCompany()->whereKey($old->id)->exists());
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->holderIds($new));

        $deleted = Activity::query()->where('description', 'role.deleted')->sole();
        $this->assertSame('security', $deleted->log_name);
        $this->assertEquals(['name' => 'Gudang Lama', 'permissions' => ['inventory.view'], 'moved_users' => 2, 'moved_to' => 'Gudang Baru'], $deleted->properties->all());

        $granted = Activity::query()->where('description', 'role.attached')->sole();
        $this->assertSame([$second->id, ['Gudang Baru'], Role::class, $this->company->id], [$granted->properties['user_id'], $granted->properties['roles'], $granted->subject_type, (int) $granted->company_id], 'only the user who lacked the target gets it');

        // A role nobody holds needs no target.
        $this->delete(route('admin.roles.destroy', $unused))->assertRedirect(route('admin.roles.index'))->assertSessionHasNoErrors();
        $this->assertFalse(Role::withoutCompany()->whereKey($unused->id)->exists());
        $this->assertEquals(['name' => 'Tanpa Pengguna', 'permissions' => ['inventory.view'], 'moved_users' => 0, 'moved_to' => null], Activity::query()->where('description', 'role.deleted')->latest('id')->first()->properties->all());
    }

    public function test_moving_users_cannot_stack_a_conflict(): void
    {
        $approver = $this->companyRole($this->company, 'Persetujuan', ['inventory.request.approve']);
        $general = $this->companyRole($this->company, 'Umum', ['ticket.view']);
        $operations = $this->companyRole($this->company, 'Operasi Gudang', ['inventory.stock.issue']);
        $user = $this->newUser($this->company);
        $this->grantRole($user, $approver, $this->company);
        $this->grantRole($user, $general, $this->company);
        $service = app(CompanyRoleService::class);

        // An operator is never exempt: approver and warehouse operator together are what the rule forbids.
        try {
            $service->delete(PlatformAdmin::factory()->create(), $general, $operations);
            $this->fail('the stacked conflict was not refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roles', $e->errors());
        }

        $this->assertTrue(Role::withoutCompany()->whereKey($general->id)->exists());
        $this->assertEqualsCanonicalizing([$approver->id, $general->id], $this->rolesOfUser($user));

        // The tenant administrator who holds roles.manage is exempt: his company, his call.
        $this->actingAs($this->holder('admin'))
            ->delete(route('admin.roles.destroy', $general), ['move_to' => $operations->public_id])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$approver->id, $operations->id], $this->rolesOfUser($user));
    }

    public function test_the_customer_template_role_cannot_be_deleted(): void
    {
        $customer = $this->roles['customer'];
        $this->actingAs($this->holder('admin'));

        $this->delete(route('admin.roles.destroy', $customer))
            ->assertSessionHasErrors(['role' => 'Peran ini dipakai untuk akun pelanggan baru.']);
        $this->assertTrue(Role::withoutCompany()->whereKey($customer->id)->exists());

        // The template key survives a rename: the role is still the one new customer accounts get.
        $this->put(route('admin.roles.update', $customer), [
            'name' => 'Akun Pelanggan',
            'permissions' => $this->permissionsOf($customer),
            OptimisticLock::FIELD => OptimisticLock::version($customer->fresh()),
        ])->assertRedirect(route('admin.roles.index'));

        $this->assertSame(['Akun Pelanggan', 'customer'], [$customer->fresh()->name, $customer->fresh()->template_key]);
        $this->delete(route('admin.roles.destroy', $customer))->assertSessionHasErrors('role');

        $this->get(route('admin.roles.index', ['per_page' => 50]))
            ->assertInertia(fn (Assert $page) => $page->where('roles.data', fn ($rows): bool => collect($rows)->firstWhere('template_key', 'customer')['can']['delete'] === false));
    }

    public function test_the_last_role_manager_cannot_be_removed_by_edit_delete_or_move(): void
    {
        $solo = Company::factory()->create(['is_active' => true]);
        $this->companyRoles($solo);
        $managing = $this->companyRole($solo, 'Pengelola', ['app.web', 'roles.manage', 'users.manage', 'ticket.view']);
        $plain = $this->companyRole($solo, 'Biasa', ['ticket.view']);
        $deputyRole = $this->companyRole($solo, 'Wakil', ['app.web', 'roles.manage']);
        $owner = $this->newUser($solo);
        $this->grantRole($owner, $managing, $solo);
        $this->actingAs($owner);

        $withoutManage = ['app.web', 'users.manage', 'ticket.view'];
        $withoutWeb = ['roles.manage', 'users.manage', 'ticket.view'];

        foreach ([$withoutManage, $withoutWeb] as $permissions) {
            $this->put(route('admin.roles.update', $managing), [
                'name' => 'Pengelola',
                'permissions' => $permissions,
                OptimisticLock::FIELD => OptimisticLock::version($managing->fresh()),
            ])->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);
        }

        $this->delete(route('admin.roles.destroy', $managing), ['move_to' => $plain->public_id])
            ->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);

        $this->assertSame(['app.web', 'roles.manage', 'ticket.view', 'users.manage'], $this->permissionsOf($managing), 'a refused edit changes nothing');
        $this->assertSame([$managing->id], $this->rolesOfUser($owner), 'a refused move keeps the roles');

        // A deactivated administrator is no administrator.
        $ghost = $this->newUser($solo, ['is_active' => false]);
        $this->grantRole($ghost, $deputyRole, $solo);
        $this->delete(route('admin.roles.destroy', $managing), ['move_to' => $plain->public_id])
            ->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);

        // With another active one, the same move goes through.
        $deputy = $this->newUser($solo);
        $this->grantRole($deputy, $deputyRole, $solo);
        $this->delete(route('admin.roles.destroy', $managing), ['move_to' => $plain->public_id])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame([$plain->id], $this->rolesOfUser($owner));

        // And now the deputy is the last one.
        $this->actingAs($deputy)
            ->put(route('admin.roles.update', $deputyRole), [
                'name' => 'Wakil',
                'permissions' => ['app.web'],
                OptimisticLock::FIELD => OptimisticLock::version($deputyRole->fresh()),
            ])->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);
        $this->assertSame(['app.web', 'roles.manage'], $this->permissionsOf($deputyRole));
    }

    public function test_a_company_without_an_administrator_can_still_be_repaired(): void
    {
        $orphan = Company::factory()->create(['is_active' => true]);
        $orphanRoles = $this->companyRoles($orphan);
        $basic = $this->companyRole($orphan, 'Dasar', ['ticket.view']);
        $manager = $this->grantPermissions($this->newUser($orphan), ['app.web', 'users.manage', 'ticket.view'], $orphan);
        $staff = $this->newUser($orphan);
        $service = app(CompanyRoleService::class);
        $platform = PlatformAdmin::factory()->create();

        $this->assertFalse($service->isAdministrator($manager, $orphan->id), 'the company has nobody holding roles.manage with web access');

        // Nothing to lose, so nothing is blocked: a manager gives a role, the operator makes, renames and deletes roles.
        $this->actingAs($manager)
            ->put(route('admin.users.roles.update', $staff), ['roles' => ['Dasar']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame([$basic->id], $this->rolesOfUser($staff));

        $repair = $service->create($platform, $orphan, 'Perbaikan', ['ticket.view']);
        $service->update($platform, $repair, 'Perbaikan Selesai', ['ticket.view', 'ticket.create'], OptimisticLock::version($repair->fresh()));
        $service->delete($platform, $orphanRoles['staff'], null);
        $service->delete($platform, $repair->fresh(), null);

        $this->assertSame(['Dasar'], Role::withoutCompany()->where('company_id', $orphan->id)->whereIn('name', ['Dasar', 'Perbaikan', 'Perbaikan Selesai', $orphanRoles['staff']->name])->pluck('name')->all());
        $this->assertSame(['role.created', 'role.updated', 'role.deleted', 'role.deleted'], Activity::query()->where('log_name', 'platform')->where('description', 'like', 'role.%')->orderBy('id')->pluck('description')->all());
        $this->assertSame(4, Activity::query()->where('log_name', 'support')->where('description', 'like', 'role.%')->count(), 'the company reads every repair');
    }

    public function test_a_stale_edit_is_refused(): void
    {
        $role = $this->companyRole($this->company, 'Dua Tab', ['ticket.view']);
        DB::table('roles')->where('id', $role->id)->update(['updated_at' => now()->subMinutes(5)]);
        $rendered = OptimisticLock::version($role->fresh());

        $this->actingAs($this->holder('admin'))
            ->put(route('admin.roles.update', $role), ['name' => 'Tab Pertama', 'permissions' => ['ticket.view'], OptimisticLock::FIELD => $rendered])
            ->assertRedirect(route('admin.roles.index'));

        $this->putJson(route('admin.roles.update', $role), ['name' => 'Tab Kedua', 'permissions' => ['ticket.view'], OptimisticLock::FIELD => $rendered])
            ->assertStatus(409)
            ->assertJsonPath('error', 'conflict')
            ->assertJsonPath('message', OptimisticLock::MESSAGE);

        $this->assertSame('Tab Pertama', $role->fresh()->name);

        $this->putJson(route('admin.roles.update', $role), ['name' => 'Tanpa Versi', 'permissions' => ['ticket.view']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);
    }

    public function test_another_company_role_is_a_404(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = $this->companyRole($other, 'Peran Asing', ['ticket.view']);
        $this->actingAs($this->holder('admin'));

        $this->get(route('admin.roles.show', $foreign))->assertNotFound();
        $this->get(route('admin.roles.edit', $foreign))->assertNotFound();
        $this->put(route('admin.roles.update', $foreign), ['name' => 'Diambil Alih', 'permissions' => [], OptimisticLock::FIELD => OptimisticLock::version($foreign->fresh())])->assertNotFound();
        $this->delete(route('admin.roles.destroy', $foreign))->assertNotFound();

        $this->assertSame('Peran Asing', $foreign->fresh()->name);
    }

    public function test_actors_without_roles_manage_get_403(): void
    {
        $manager = $this->holderOf(['app.web', 'users.manage', 'ticket.view']);
        $role = $this->companyRole($this->company, 'Dasar', ['ticket.view']);
        $this->actingAs($manager);

        $this->get(route('admin.roles.index'))->assertForbidden();
        $this->get(route('admin.roles.create'))->assertForbidden();
        $this->get(route('admin.roles.show', $role))->assertForbidden();
        $this->get(route('admin.roles.edit', $role))->assertForbidden();
        $this->post(route('admin.roles.store'), ['name' => 'Tanpa Hak', 'permissions' => []])->assertForbidden();
        $this->put(route('admin.roles.update', $role), ['name' => 'Tanpa Hak', 'permissions' => [], OptimisticLock::FIELD => OptimisticLock::version($role->fresh())])->assertForbidden();
        $this->delete(route('admin.roles.destroy', $role))->assertForbidden();

        $this->assertFalse(Role::withoutCompany()->where('name', 'Tanpa Hak')->exists());
        $this->assertSame('Dasar', $role->fresh()->name);
    }

    /** A member of the company who holds its copy of the template. */
    private function holder(string $templateKey): User
    {
        return $this->grantRole($this->newUser($this->company), $templateKey, $this->company);
    }

    /**
     * A member of the company who holds one role made for these permissions.
     *
     * @param  list<string>  $permissions
     */
    private function holderOf(array $permissions): User
    {
        return $this->grantPermissions($this->newUser($this->company), $permissions, $this->company);
    }

    /** @param  array<string, mixed>  $attributes */
    private function newUser(Company $company, array $attributes = []): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
            ...$attributes,
        ]);
        app(CompanyMembershipService::class)->grant($user, $company, true);

        return $user;
    }

    private function role(string $name): Role
    {
        return Role::withoutCompany()->where('company_id', $this->company->id)->where('name', $name)->firstOrFail();
    }

    /** @return list<string> */
    private function permissionsOf(Role $role): array
    {
        return $role->permissions()->pluck('name')->sort()->values()->all();
    }

    /** @return list<int> */
    private function holderIds(Role $role): array
    {
        return DB::table('model_has_roles')->where('role_id', $role->id)->pluck('model_id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function rolesOfUser(User $user): array
    {
        return DB::table('model_has_roles')->where('model_id', $user->id)->pluck('role_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }
}
