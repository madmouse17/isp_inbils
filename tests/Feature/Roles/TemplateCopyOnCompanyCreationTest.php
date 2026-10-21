<?php

namespace Tests\Feature\Roles;

use App\Actions\Platform\ProvisionTenantAction;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use App\Services\Core\CompanyService;
use App\Services\Core\CustomerService;
use App\Services\Core\SetupWizardService;
use Database\Seeders\CompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A company copies the platform's role templates once, when it is created, and owns its roles from then on (ADR-004).
 */
class TemplateCopyOnCompanyCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_setup_wizard_copies_templates_and_makes_the_creator_admin(): void
    {
        $creator = User::factory()->create(['company_id' => null]);

        $company = app(SetupWizardService::class)->createFor($creator, $this->wizardData('WIZ'));

        $this->assertCopiesOfEveryTemplate($company);
        $this->assertSame(['admin'], $this->templateKeysHeldBy($creator, $company));
        $this->assertTrue(CompanyService::runAs($company->id, fn (): bool => $creator->can('roles.manage') && $creator->can('app.web')));
    }

    public function test_platform_provisioning_copies_templates(): void
    {
        Notification::fake();

        $company = app(ProvisionTenantAction::class)->execute(PlatformAdmin::factory()->create(), [
            'name' => 'PT Nusa Net', 'code' => 'NUSA', 'timezone' => 'Asia/Makassar', 'currency' => 'IDR',
            'admin_name' => 'Rina Pratama', 'admin_email' => 'rina@nusanet.test', 'catalog' => 'starter',
        ]);

        $admin = User::query()->where('email', 'rina@nusanet.test')->firstOrFail();

        $this->assertCopiesOfEveryTemplate($company);
        $this->assertSame(['admin'], $this->templateKeysHeldBy($admin, $company));
    }

    public function test_a_subsidiary_gets_the_templates(): void
    {
        $head = Company::factory()->create();

        $subsidiary = app(CompanyHierarchyService::class)->createSubsidiary($head, ['name' => 'Cabang Satu', 'code' => 'CB1']);

        $this->assertCopiesOfEveryTemplate($subsidiary);
        $this->assertSame(0, Role::forCompany($head->id)->count(), 'the head was not touched');
    }

    public function test_copying_twice_creates_nothing(): void
    {
        $company = Company::factory()->create();
        $service = app(CompanyRoleService::class);

        $first = $service->copyTemplates($company);
        $second = $service->copyTemplates($company);
        app(CompanySeeder::class)->runFor($company, CompanySeeder::CATALOG_EMPTY);

        $this->assertCount(8, $first);
        $this->assertEquals(collect($first)->map->id->all(), collect($second)->map->id->all(), 'the second call returns the copies of the first');
        $this->assertSame(8, Role::forCompany($company->id)->count());
    }

    public function test_a_company_that_deleted_a_role_does_not_get_it_back(): void
    {
        $company = Company::factory()->create();
        $roles = $this->companyRoles($company);
        $roles['finance']->delete();

        app(CompanyRoleService::class)->copyTemplates($company);
        app(CompanySeeder::class)->runFor($company, CompanySeeder::CATALOG_EMPTY);

        $this->assertNull(Role::forCompany($company->id)->where('template_key', 'finance')->first(), 'the company owns its roles');
        $this->assertSame(7, Role::forCompany($company->id)->count());
    }

    public function test_template_edits_do_not_touch_existing_companies(): void
    {
        $existing = Company::factory()->create();
        $before = $this->companyRoles($existing)['staff'];
        $permissions = $before->permissions()->pluck('name')->sort()->values()->all();

        $template = RoleTemplate::query()->where('key', 'staff')->firstOrFail();
        $template->forceFill(['name' => 'Staf Baru'])->save();
        $template->permissions()->sync(DB::table('permissions')->whereIn('name', ['dashboard.view', 'report.audit.view'])->pluck('id')->all());

        $this->assertSame('Staf', $before->fresh()->name, 'the copy keeps its name');
        $this->assertSame($permissions, $before->fresh()->permissions()->pluck('name')->sort()->values()->all(), 'and its permissions');

        $created = $this->companyRoles(Company::factory()->create())['staff'];
        $this->assertSame('Staf Baru', $created->name);
        $this->assertEqualsCanonicalizing(['dashboard.view', 'report.audit.view'], $created->permissions()->pluck('name')->all());
    }

    public function test_a_new_customer_account_gets_the_customer_template_role_after_a_rename(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $roles = $this->companyRoles($company);
        $roles['customer']->forceFill(['name' => 'Akun Pelanggan'])->save();

        $customer = $this->newCustomer($company, 'CUS-RENAME-1');

        $this->assertSame([$roles['customer']->id], DB::table('model_has_roles')->where('model_id', $customer->user_id)->pluck('role_id')->map(fn (mixed $id): int => (int) $id)->all());
        $this->assertTrue(CompanyService::runAs($company->id, fn (): bool => User::query()->findOrFail($customer->user_id)->can('portal.access')));
    }

    public function test_a_customer_account_cannot_be_made_in_a_company_without_the_customer_template_role(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $this->companyRoles($company)['customer']->delete();

        try {
            $this->newCustomer($company, 'CUS-NONE-1');
            $this->fail('A customer account needs the role of the Pelanggan template.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $this->assertFalse(Customer::withoutCompany()->where('code', 'CUS-NONE-1')->exists(), 'nothing is half-created');
    }

    public function test_membership_revoke_detaches_that_company_roles(): void
    {
        $home = Company::factory()->create();
        $other = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $home->id]);
        $memberships = app(CompanyMembershipService::class);
        $memberships->grant($user, $home, true);
        $memberships->grant($user, $other);
        $this->grantRole($user, 'staff', $home);
        $this->grantRole($user, 'manager', $other);
        // Another administrator keeps the company administered, so the revoke is not refused (G5).
        $this->grantRole(User::factory()->create(['company_id' => $other->id]), 'admin', $other);

        $memberships->revoke($user, $other);

        $this->assertSame(['staff'], $this->templateKeysHeldBy($user, $home), 'his roles in the other company stay');
        $this->assertSame([], $this->templateKeysHeldBy($user, $other));
        $this->assertFalse(DB::table('company_user')->where('user_id', $user->id)->where('company_id', $other->id)->exists());
    }

    public function test_revoking_the_last_administrator_is_refused(): void
    {
        $home = Company::factory()->create();
        $other = Company::factory()->create();
        $admin = User::factory()->create(['company_id' => $home->id]);
        $memberships = app(CompanyMembershipService::class);
        $memberships->grant($admin, $home, true);
        $memberships->grant($admin, $other);
        $this->grantRole($admin, 'admin', $other);

        try {
            $memberships->revoke($admin, $other);
            $this->fail('The only administrator of a company cannot be revoked.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roles', $e->errors());
        }

        $this->assertSame(['admin'], $this->templateKeysHeldBy($admin, $other), 'a refusal changes nothing');
        $this->assertTrue(DB::table('company_user')->where('user_id', $admin->id)->where('company_id', $other->id)->exists());
    }

    public function test_a_membership_gives_no_role_by_itself(): void
    {
        $home = Company::factory()->create();
        $other = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $home->id]);
        $this->grantRole($user, 'staff', $home);
        $this->companyRoles($other);

        app(CompanyMembershipService::class)->grant($user, $other);

        $this->assertSame([], $this->templateKeysHeldBy($user, $other));
    }

    /** @return array<string, string> */
    private function wizardData(string $code): array
    {
        return [
            'name' => "PT {$code}", 'code' => $code, 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta',
            'date_format' => 'd M Y', 'datetime_format' => 'd M Y H:i', 'admin_name' => "Admin {$code}",
        ];
    }

    private function assertCopiesOfEveryTemplate(Company $company): void
    {
        $templates = RoleTemplate::query()->with('permissions')->orderBy('id')->get();
        $roles = Role::forCompany($company->id)->with('permissions')->get()->keyBy('template_key');

        $this->assertCount(8, $templates);
        $this->assertEqualsCanonicalizing($templates->pluck('key')->all(), $roles->keys()->all(), 'one copy per template');

        foreach ($templates as $template) {
            $role = $roles[$template->key];

            $this->assertSame($template->name, $role->name);
            $this->assertEqualsCanonicalizing($template->permissions->pluck('name')->all(), $role->permissions->pluck('name')->all(), $template->key);
        }
    }

    /** @return list<string> the template keys of the roles the user holds in the company, sorted */
    private function templateKeysHeldBy(User $user, Company $company): array
    {
        $keys = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.company_id', $company->id)
            ->pluck('roles.template_key')
            ->all();
        sort($keys);

        return $keys;
    }

    private function newCustomer(Company $company, string $code): Customer
    {
        return CompanyService::runAs($company->id, fn (): Customer => CustomerService::createWithUser([
            'code' => $code, 'name' => 'Pelanggan '.$code, 'type' => 'Individual', 'email' => strtolower($code).'@example.test', 'is_active' => true,
        ]));
    }
}
