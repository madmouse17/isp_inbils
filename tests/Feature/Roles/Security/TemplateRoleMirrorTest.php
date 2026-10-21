<?php

namespace Tests\Feature\Roles\Security;

use App\Models\Core\Company;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use App\Services\Core\CompanyService;
use App\Services\Platform\RoleTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Platform\TenantFixtures;
use Tests\TestCase;

/**
 * M2: a company owns its role copies, so "the copy of the same template" is not "the same rights". A member who is
 * enrolled in another company gets the template-matched roles only as far as he holds what they grant in the company he
 * comes from; the rest is left for a person to give, and the operator is told. A deleted template's key is never taken
 * again while a company still holds a copy that carries it.
 */
class TemplateRoleMirrorTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
    }

    /** The company took permissions away from its copy of a template, as it may. @param list<string> $permissions */
    private function trim(Company $company, string $templateKey, array $permissions): Role
    {
        $role = $this->companyRoles($company)[$templateKey];
        $role->permissions()->detach(Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions)->pluck('id')->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    /** @return list<string> the names of the roles the user holds in the company */
    private function rolesIn(User $user, Company $company): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.company_id', $company->id)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();
    }

    public function test_a_copy_the_company_trimmed_is_not_widened_by_the_copy_of_the_other_company(): void
    {
        $staff = $this->alpha['staff'];
        $this->trim($this->alpha['root'], 'staff', ['billing.create', 'billing.payment.record']);
        $technician = $this->tenantUser($this->alpha['root'], 'technician');

        $staffResult = app(CompanyRoleService::class)->mirrorTemplateRoles($staff, $this->alpha['root'], $this->alpha['child']);
        $technicianResult = app(CompanyRoleService::class)->mirrorTemplateRoles($technician, $this->alpha['root'], $this->alpha['child']);

        $this->assertSame(['granted' => [], 'by_hand' => ['Staf']], $staffResult, 'the Staf of the child carries billing.create, which he does not hold at home');
        $this->assertSame([], $this->rolesIn($staff, $this->alpha['child']));
        $this->assertSame(['granted' => ['Teknisi'], 'by_hand' => []], $technicianResult, 'an untouched copy is mirrored as before');
        $this->assertSame(['Teknisi'], $this->rolesIn($technician, $this->alpha['child']));
    }

    public function test_an_admin_copy_repurposed_as_a_narrow_role_does_not_become_the_full_admin_of_the_new_company(): void
    {
        $root = $this->alpha['root'];
        $operator = User::factory()->create(['company_id' => $root->id, 'email_verified_at' => now(), 'is_active' => true]);
        app(CompanyMembershipService::class)->grant($operator, $root, true);
        $admin = $this->companyRoles($root)['admin'];
        $admin->forceFill(['name' => 'Operator'])->save();
        $admin->permissions()->sync(Permission::query()->where('guard_name', 'web')->whereIn('name', ['app.web', 'customer.view'])->pluck('id')->all());
        $this->grantRole($operator, $admin, $root);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $result = app(CompanyRoleService::class)->mirrorTemplateRoles($operator, $root, $this->alpha['child']);

        $this->assertSame(['granted' => [], 'by_hand' => ['Admin']], $result);
        $this->assertSame([], $this->rolesIn($operator, $this->alpha['child']));
    }

    public function test_what_the_member_holds_through_other_roles_counts(): void
    {
        $staff = $this->alpha['staff'];
        $this->trim($this->alpha['root'], 'staff', ['billing.create', 'billing.payment.record']);
        $this->grantRole($staff, $this->companyRole($this->alpha['root'], 'Kasir', ['billing.create', 'billing.payment.record']), $this->alpha['root']);

        $result = app(CompanyRoleService::class)->mirrorTemplateRoles($staff, $this->alpha['root'], $this->alpha['child']);

        $this->assertSame(['granted' => ['Staf'], 'by_hand' => []], $result);
        $this->assertSame(['Staf'], $this->rolesIn($staff, $this->alpha['child']));
    }

    public function test_a_role_he_already_holds_in_the_other_company_is_kept_and_named(): void
    {
        $staff = $this->alpha['staff'];
        $this->trim($this->alpha['root'], 'staff', ['billing.create']);
        $this->grantRole($staff, 'staff', $this->alpha['child']);

        $result = app(CompanyRoleService::class)->mirrorTemplateRoles($staff, $this->alpha['root'], $this->alpha['child']);

        $this->assertSame(['granted' => ['Staf'], 'by_hand' => []], $result, 'somebody gave it to him on purpose; the copy has nothing to add');
        $this->assertSame(['Staf'], $this->rolesIn($staff, $this->alpha['child']));
    }

    public function test_the_operator_is_told_and_the_audit_row_lists_what_each_member_was_given(): void
    {
        $this->trim($this->alpha['root'], 'staff', ['billing.create']);

        $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $this->alpha['root']), [
            'name' => 'Alpha Selatan',
            'code' => 'ALS',
            'member_ids' => [$this->alpha['staff']->public_id, $this->alpha['branch']->public_id],
        ])->assertRedirect()->assertSessionHas('success', 'Anak perusahaan dibuat.'
            .' Anggota ini belum punya peran di anak perusahaan baru dan perlu diberi peran di Pengguna: '.$this->alpha['staff']->name.'.'
            .' Peran ini lebih luas daripada yang dipegang anggotanya di perusahaan asal, jadi tidak disalin dan perlu diberikan sendiri di Pengguna: '.$this->alpha['staff']->name.' (Staf).');

        $subsidiary = Company::query()->where('code', 'ALS')->firstOrFail();
        $this->assertSame([], $this->rolesIn($this->alpha['staff'], $subsidiary));
        $this->assertSame(['Manajer'], $this->rolesIn($this->alpha['branch'], $subsidiary));

        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.subsidiary_created')->sole();
        $this->assertSame([$this->alpha['staff']->id], $row->properties['members_without_roles']);
        $byMember = collect($row->properties['member_roles'])->mapWithKeys(fn (array $entry): array => [$entry['user_id'] => [$entry['granted'], $entry['by_hand']]])->all();
        $this->assertEquals([
            $this->alpha['staff']->id => [[], ['Staf']],
            $this->alpha['branch']->id => [['Manajer'], []],
        ], $byMember, 'what each member was given, and what is left for a person to give');
    }

    public function test_the_commands_say_which_roles_were_left_for_a_person_to_give(): void
    {
        $this->trim($this->alpha['root'], 'staff', ['billing.create']);
        $staff = $this->alpha['staff'];

        $this->artisan('inbils:company:grant-membership', ['user' => $staff->email, 'company' => $this->alpha['child']->code])
            ->expectsOutputToContain("{$staff->email} holds no role in {$this->alpha['child']->name}: assign roles in Pengguna.")
            ->expectsOutputToContain("{$staff->email} was not given, wider than what he holds at home: assign in Pengguna: Staf.")
            ->assertExitCode(0);

        $this->artisan('inbils:company:create-subsidiary', ['head' => 'ALP', 'name' => 'Alpha Timur', 'code' => 'ALT', '--member' => [$staff->email]])
            ->expectsOutputToContain("{$staff->email} was not given, wider than what he holds at home: assign in Pengguna: Staf.")
            ->assertExitCode(0);
        $this->assertSame([], $this->rolesIn($staff, Company::query()->where('code', 'ALT')->firstOrFail()));
    }

    public function test_a_deleted_templates_key_is_not_taken_again_while_a_company_holds_a_copy_of_it(): void
    {
        $templates = app(RoleTemplateService::class);
        $template = $templates->create($this->operator, 'Tim Ops', ['ticket.view']);
        $company = Company::factory()->create(['is_active' => true]);
        $this->assertSame('tim_ops', app(CompanyRoleService::class)->copyTemplates($company)['tim_ops']->template_key);

        $templates->delete($this->operator, $template);
        $this->assertFalse(RoleTemplate::query()->where('key', 'tim_ops')->exists());
        $this->assertTrue(CompanyService::runAs($company->id, fn (): bool => Role::query()->where('template_key', 'tim_ops')->exists()), 'the copy keeps its provenance');

        try {
            $templates->create($this->operator, 'Tim Ops', ['billing.view']);
            $this->fail('A new template took the key of the deleted one.');
        } catch (ValidationException $e) {
            $this->assertSame(['name' => ['Nama templat sudah dipakai.']], $e->errors());
        }

        // The form says so before the service has to.
        $this->asOperator()->post(route('platform.role-templates.store'), ['name' => 'Tim Ops', 'permissions' => ['billing.view']])
            ->assertSessionHasErrors(['name' => 'Nama templat sudah dipakai.']);
        $this->assertFalse(RoleTemplate::query()->where('key', 'tim_ops')->exists());

        // Once no copy carries the key, nothing can be mistaken for its provenance any more.
        Role::withoutCompany()->where('template_key', 'tim_ops')->delete();
        $this->asOperator()->post(route('platform.role-templates.store'), ['name' => 'Tim Ops', 'permissions' => ['billing.view']])
            ->assertRedirect(route('platform.role-templates.index'));
        $this->assertTrue(RoleTemplate::query()->where('key', 'tim_ops')->exists());
    }
}
