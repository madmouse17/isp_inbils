<?php

namespace Tests\Feature\Roles;

use App\Models\Core\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use App\Support\UserDeletionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The roles a user holds in a company (Pengguna and the member dialog): the picker, the guards of G1, G2, G4 and G5,
 * a member homed elsewhere, and the roles of customer accounts that Pengguna never touches (G6).
 */
class UserRoleAssignmentTest extends TestCase
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

    public function test_the_picker_offers_company_roles_without_portal_roles(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $this->companyRole($other, 'Peran Perusahaan Lain', ['ticket.view']);
        $this->companyRole($this->company, 'Cuma Tiket', ['ticket.view']);
        $this->companyRole($this->company, 'Kasir', ['billing.update', 'ticket.view']);
        $admin = $this->holder('admin');
        $limited = $this->holderOf(['app.web', 'users.manage', 'ticket.view']);
        $target = $this->newUser();

        $options = fn (callable $check) => fn (Assert $page) => $page->where('roleOptions', $check);
        $everyOption = fn ($options): bool => collect($options)->pluck('name')->doesntContain($this->roles['customer']->name)
            && collect($options)->pluck('name')->doesntContain('Peran Perusahaan Lain')
            && collect($options)->pluck('name')->contains('Cuma Tiket')
            && collect($options)->pluck('name')->contains($this->roles['admin']->name)
            && collect($options)->every(fn (array $option): bool => array_keys($option) === ['name', 'grantable']);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertInertia($options($everyOption));
        $this->get(route('admin.users.index'))->assertInertia($options(fn ($rows): bool => collect($rows)->every(fn (array $option): bool => $option['grantable'] === true)));

        // What the actor does not hold stays on the list, switched off: the form shows it and cannot send it.
        $this->actingAs($limited)->get(route('admin.users.index'))
            ->assertInertia($options(fn ($rows): bool => collect($rows)->firstWhere('name', 'Cuma Tiket')['grantable'] === true
                && collect($rows)->firstWhere('name', 'Kasir')['grantable'] === false
                && collect($rows)->firstWhere('name', $this->roles['admin']->name)['grantable'] === false));

        // The two forms get the same list.
        $this->get(route('admin.users.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Create')->where('roles', $everyOption));
        $this->get(route('admin.users.edit', $target))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Edit')->where('roles', $everyOption));
    }

    public function test_assigning_a_richer_role_is_refused(): void
    {
        $this->companyRole($this->company, 'Cuma Tiket', ['ticket.view']);
        $this->companyRole($this->company, 'Kasir', ['billing.update', 'ticket.view']);
        $limited = $this->holderOf(['app.web', 'users.manage', 'ticket.view']);
        $target = $this->newUser();
        $refusal = 'Anda tidak dapat memberi hak akses yang tidak Anda miliki: billing.update.';
        $this->actingAs($limited);
        Activity::query()->delete();

        $this->put(route('admin.users.roles.update', $target), ['roles' => ['Kasir']])->assertSessionHasErrors(['roles' => $refusal]);
        $this->assertSame([], $this->rolesOfUser($target));

        $this->put(route('admin.users.update', $target), ['name' => $target->name, 'email' => $target->email, 'roles' => ['Kasir']])
            ->assertSessionHasErrors(['roles' => $refusal]);
        $this->assertSame([], $this->rolesOfUser($target));

        // A new user is not left behind without his roles: the refusal takes the whole save with it.
        $this->post(route('admin.users.store'), ['name' => 'Calon Kasir', 'email' => 'calon.kasir@example.test', 'password' => 'rahasia-1234', 'roles' => ['Kasir']])
            ->assertSessionHasErrors(['roles' => $refusal]);
        $this->assertFalse(User::query()->where('email', 'calon.kasir@example.test')->exists());
        $this->assertSame(0, Activity::query()->where('description', 'like', 'role.%')->count());

        $this->put(route('admin.users.roles.update', $target), ['roles' => ['Cuma Tiket']])
            ->assertRedirect()
            ->assertSessionHas('success', 'Peran pengguna diperbarui.');
        $this->assertSame([$this->roleId('Cuma Tiket')], $this->rolesOfUser($target));

        $granted = Activity::query()->where('description', 'role.attached')->sole();
        $this->assertSame([Role::class, $this->roleId('Cuma Tiket'), $target->id, ['Cuma Tiket'], $limited->id], [$granted->subject_type, $granted->subject_id, $granted->properties['user_id'], $granted->properties['roles'], $granted->causer_id]);

        $this->post(route('admin.users.store'), ['name' => 'Calon Staf', 'email' => 'calon.staf@example.test', 'password' => 'rahasia-1234', 'roles' => ['Cuma Tiket']])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame([$this->roleId('Cuma Tiket')], $this->rolesOfUser(User::query()->where('email', 'calon.staf@example.test')->firstOrFail()));
    }

    public function test_a_member_homed_elsewhere_gets_roles_here_only(): void
    {
        $branch = Company::factory()->create(['is_active' => true]);
        $branchRoles = $this->companyRoles($branch);
        $branchAdmin = $this->grantRole($this->newUser($branch), 'admin', $branch);
        $guest = $this->grantRole($this->newUser(), 'staff', $this->company);
        app(CompanyMembershipService::class)->grant($guest, $branch);
        $stranger = $this->newUser();
        $this->actingAs($branchAdmin);

        // He is listed among the members of the branch, for his roles there, and nothing else is offered for him.
        $this->get(route('admin.users.index', ['search' => $guest->email]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('users.data', fn ($rows): bool => count($rows) === 1
                && $rows[0]['id'] === $guest->public_id
                && $rows[0]['is_member_only'] === true
                && $rows[0]['roles'] === []
                && $rows[0]['can']['delete'] === false
                && $rows[0]['can']['assign_roles'] === true));

        $this->put(route('admin.users.roles.update', $guest), ['roles' => [$branchRoles['technician']->name]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->roles['staff']->id, $branchRoles['technician']->id], $this->rolesOfUser($guest), 'his role of the other company is untouched');

        $this->get(route('admin.users.index', ['search' => $guest->email]))
            ->assertInertia(fn (Assert $page) => $page->where('users.data', fn ($rows): bool => $rows[0]['roles'] === [$branchRoles['technician']->name]));

        // Taking the roles away takes this company's only.
        $this->put(route('admin.users.roles.update', $guest), ['roles' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$this->roles['staff']->id], $this->rolesOfUser($guest));

        // His login belongs to his home company.
        $this->get(route('admin.users.edit', $guest))->assertNotFound();
        $this->put(route('admin.users.update', $guest), ['name' => 'Diambil Alih', 'email' => $guest->email, 'roles' => []])->assertNotFound();
        $this->delete(route('admin.users.destroy', $guest))->assertNotFound();
        $this->assertSame($guest->name, $guest->fresh()->name);

        // A user who is no member has no roles here to give.
        $this->put(route('admin.users.roles.update', $stranger), ['roles' => [$branchRoles['technician']->name]])->assertNotFound();
        $this->assertSame([], $this->rolesOfUser($stranger));
    }

    public function test_a_member_gets_the_template_matched_roles_of_the_other_company(): void
    {
        $branch = Company::factory()->create(['is_active' => true]);
        $branchRoles = $this->companyRoles($branch);
        $custom = $this->companyRole($this->company, 'Khusus', ['ticket.view']);
        $technician = $this->grantRole($this->newUser(), 'technician', $this->company);
        $customOnly = $this->grantRole($this->newUser(), $custom, $this->company);
        foreach ([$technician, $customOnly] as $member) {
            app(CompanyMembershipService::class)->grant($member, $branch);
        }
        $service = app(CompanyRoleService::class);

        $this->assertSame(['granted' => [$branchRoles['technician']->name], 'by_hand' => []], $service->mirrorTemplateRoles($technician, $this->company, $branch));
        $this->assertEqualsCanonicalizing([$this->roles['technician']->id, $branchRoles['technician']->id], $this->rolesOfUser($technician));

        // Again: nothing more is attached, and the roles that match are still named.
        $this->assertSame(['granted' => [$branchRoles['technician']->name], 'by_hand' => []], $service->mirrorTemplateRoles($technician, $this->company, $branch));
        $this->assertCount(2, $this->rolesOfUser($technician));

        // A role the company made has no template, so it has no counterpart in the other company.
        $this->assertSame(['granted' => [], 'by_hand' => []], $service->mirrorTemplateRoles($customOnly, $this->company, $branch));
        $this->assertSame([$custom->id], $this->rolesOfUser($customOnly));
    }

    public function test_portal_roles_survive_a_pengguna_save(): void
    {
        $this->companyRole($this->company, 'Cuma Tiket', ['ticket.view']);
        $portal = $this->roles['customer'];
        $account = $this->grantRole($this->newUser(), $portal, $this->company);
        $staff = $this->newUser();
        $this->actingAs($this->holder('admin'));
        Activity::query()->delete();

        $form = fn (User $user, array $roles): array => ['name' => $user->name, 'email' => $user->email, 'is_active' => true, 'roles' => $roles];

        // The form never lists the role of a customer account, so a save without it must not take it away.
        $this->put(route('admin.users.update', $account), $form($account, []))->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $this->assertSame([$portal->id], $this->rolesOfUser($account));

        $this->put(route('admin.users.update', $account), $form($account, ['Cuma Tiket']))->assertRedirect(route('admin.users.index'));
        $this->assertEqualsCanonicalizing([$portal->id, $this->roleId('Cuma Tiket')], $this->rolesOfUser($account));
        $this->assertSame(0, Activity::query()->where('description', 'role.detached')->count());

        // And Pengguna never gives it: the name is ignored, on an edit and on a new user.
        $this->put(route('admin.users.update', $staff), $form($staff, [$portal->name]))->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $this->assertSame([], $this->rolesOfUser($staff));

        $this->put(route('admin.users.roles.update', $staff), ['roles' => [$portal->name]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([], $this->rolesOfUser($staff));

        $this->post(route('admin.users.store'), ['name' => 'Bukan Pelanggan', 'email' => 'bukan.pelanggan@example.test', 'password' => 'rahasia-1234', 'roles' => [$portal->name]])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame([], $this->rolesOfUser(User::query()->where('email', 'bukan.pelanggan@example.test')->firstOrFail()));
    }

    public function test_the_last_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $solo = Company::factory()->create(['is_active' => true]);
        $this->companyRoles($solo);
        $managing = $this->companyRole($solo, 'Pengelola', ['app.web', 'roles.manage', 'users.manage', 'ticket.view']);
        $deputyRole = $this->companyRole($solo, 'Wakil', ['app.web', 'roles.manage', 'users.manage', 'ticket.view']);
        $owner = $this->grantRole($this->newUser($solo), $managing, $solo);
        $form = ['name' => $owner->name, 'email' => $owner->email, 'is_active' => true, 'roles' => ['Pengelola']];
        $this->actingAs($owner);

        $this->put(route('admin.users.roles.update', $owner), ['roles' => []])
            ->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);
        $this->put(route('admin.users.update', $owner), [...$form, 'roles' => []])
            ->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);
        $this->put(route('admin.users.update', $owner), [...$form, 'is_active' => false])
            ->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);
        $this->assertSame([$managing->id], $this->rolesOfUser($owner));
        $this->assertTrue($owner->fresh()->is_active);

        // Nobody deletes the last administrator, not even he himself from his profile.
        $this->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors(['user' => CompanyRoleService::LAST_ADMINISTRATOR]);
        $this->assertTrue(User::query()->whereKey($owner->id)->exists());
        $this->assertAuthenticatedAs($owner);

        // A deactivated administrator is no administrator: nothing changes.
        $ghost = $this->newUser($solo, ['is_active' => false]);
        $this->grantRole($ghost, $deputyRole, $solo);
        $this->put(route('admin.users.roles.update', $owner), ['roles' => []])
            ->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);
        try {
            UserDeletionGuard::delete($owner);
            $this->fail('the last administrator was deletable');
        } catch (ValidationException $e) {
            $this->assertSame(['user' => [CompanyRoleService::LAST_ADMINISTRATOR]], $e->errors());
        }
        $this->assertTrue(User::query()->whereKey($owner->id)->exists());

        // With another active administrator the company is safe, and the same steps are allowed.
        $deputy = $this->grantRole($this->newUser($solo), $deputyRole, $solo);
        $spare = $this->grantRole($this->newUser($solo), $deputyRole, $solo);
        UserDeletionGuard::delete($spare);
        $this->assertFalse(User::query()->whereKey($spare->id)->exists(), 'with others left, an administrator may be deleted');
        $this->actingAs($deputy);

        $this->put(route('admin.users.update', $owner), [...$form, 'is_active' => false])->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $this->assertFalse($owner->fresh()->is_active);
        $this->put(route('admin.users.update', $owner), [...$form, 'is_active' => true])->assertRedirect(route('admin.users.index'));
        $this->put(route('admin.users.roles.update', $owner), ['roles' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([], $this->rolesOfUser($owner));

        // And now the deputy is the last one.
        $this->put(route('admin.users.roles.update', $deputy), ['roles' => []])
            ->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);
        $this->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors(['user' => CompanyRoleService::LAST_ADMINISTRATOR]);
        $this->assertTrue(User::query()->whereKey($deputy->id)->exists());
    }

    public function test_a_user_richer_than_the_actor_cannot_be_changed(): void
    {
        $limited = $this->holderOf(['app.web', 'users.manage', 'ticket.view']);
        $rich = $this->holder('admin');
        $plain = $this->newUser();
        $this->actingAs($limited);

        $this->get(route('admin.users.edit', $rich))->assertForbidden();
        $this->put(route('admin.users.update', $rich), ['name' => 'Diambil Alih', 'email' => $rich->email, 'roles' => []])->assertForbidden();
        $this->put(route('admin.users.roles.update', $rich), ['roles' => []])->assertForbidden();
        $this->delete(route('admin.users.destroy', $rich))->assertForbidden();
        $this->assertSame($rich->name, $rich->fresh()->name);
        $this->assertSame([$this->roles['admin']->id], $this->rolesOfUser($rich));

        $this->get(route('admin.users.index', ['per_page' => 50]))
            ->assertInertia(fn (Assert $page) => $page->where('users.data', fn ($rows): bool => collect($rows)->firstWhere('id', $rich->public_id)['can']['assign_roles'] === false
                && collect($rows)->firstWhere('id', $rich->public_id)['can']['delete'] === false
                && collect($rows)->firstWhere('id', $plain->public_id)['can']['assign_roles'] === true
                && collect($rows)->firstWhere('id', $plain->public_id)['can']['delete'] === true));

        $this->get(route('admin.users.edit', $plain))->assertOk();
        $this->put(route('admin.users.roles.update', $plain), ['roles' => []])->assertRedirect()->assertSessionHasNoErrors();
    }

    /** A member of the company who holds its copy of the template. */
    private function holder(string $templateKey, ?Company $company = null): User
    {
        return $this->grantRole($this->newUser($company), $templateKey, $company ?? $this->company);
    }

    /**
     * A member of the company who holds one role made for these permissions.
     *
     * @param  list<string>  $permissions
     */
    private function holderOf(array $permissions): User
    {
        return $this->grantPermissions($this->newUser(), $permissions, $this->company);
    }

    /** @param  array<string, mixed>  $attributes */
    private function newUser(?Company $company = null, array $attributes = []): User
    {
        $company ??= $this->company;
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
            ...$attributes,
        ]);
        app(CompanyMembershipService::class)->grant($user, $company, true);

        return $user;
    }

    private function roleId(string $name): int
    {
        return (int) Role::withoutCompany()->where('company_id', $this->company->id)->where('name', $name)->value('id');
    }

    /** @return list<int> */
    private function rolesOfUser(User $user): array
    {
        return DB::table('model_has_roles')->where('model_id', $user->id)->pluck('role_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }
}
