<?php

namespace Tests\Feature\Roles\Security;

use App\Models\Core\Company;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Platform\TenantFixtures;
use Tests\TestCase;

/**
 * L5: the role of customer accounts and a back-office role are two audiences. Customers are outside parties: if the role
 * every customer account gets could hold app.web, each of them (and anyone with customer.create can make more) would
 * reach /admin. No role holds portal.access together with app.web, wherever it is written, and not even a set that holds
 * roles.manage is exempt.
 */
class CustomerRoleAudienceTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const REFUSAL = 'Peran akun pelanggan (portal.access) tidak boleh memuat akses web (app.web).';

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
    }

    /** @return list<string> */
    private function permissionsOf(Role|RoleTemplate $role): array
    {
        return $role->permissions()->pluck('name')->sort()->values()->all();
    }

    public function test_a_company_cannot_give_a_role_both_audiences(): void
    {
        $customer = $this->companyRoles($this->alpha['root'])['customer'];
        $held = $this->permissionsOf($customer);
        $this->actingAs($this->alpha['admin']);

        $this->post(route('admin.roles.store'), ['name' => 'Pelanggan Staf', 'permissions' => ['portal.access', 'app.web']])
            ->assertSessionHasErrors(['permissions' => self::REFUSAL]);
        $this->post(route('admin.roles.store'), ['name' => 'Pelanggan Staf', 'permissions' => ['portal.access', 'app.web', 'roles.manage']])
            ->assertSessionHasErrors(['permissions' => self::REFUSAL]);
        $this->assertFalse(Role::withoutCompany()->where('name', 'Pelanggan Staf')->exists());

        $this->put(route('admin.roles.update', $customer), [
            'name' => $customer->name,
            'permissions' => [...$held, 'app.web'],
            OptimisticLock::FIELD => OptimisticLock::version($customer),
        ])->assertSessionHasErrors(['permissions' => self::REFUSAL]);
        $this->assertSame($held, $this->permissionsOf($customer));

        // Each audience on its own is fine.
        $this->post(route('admin.roles.store'), ['name' => 'Portal Saja', 'permissions' => ['portal.access', 'ticket.view']])->assertRedirect(route('admin.roles.index'));
        $this->post(route('admin.roles.store'), ['name' => 'Web Saja', 'permissions' => ['app.web', 'ticket.view']])->assertRedirect(route('admin.roles.index'));
    }

    public function test_the_platform_cannot_give_a_template_or_a_repaired_role_both_audiences(): void
    {
        $template = RoleTemplate::query()->where('key', 'customer')->firstOrFail();
        $held = $this->permissionsOf($template);
        $customer = $this->companyRoles($this->alpha['root'])['customer'];
        $heldByRole = $this->permissionsOf($customer);

        $this->asOperator()->post(route('platform.role-templates.store'), ['name' => 'Pelanggan Staf', 'permissions' => ['portal.access', 'app.web']])
            ->assertSessionHasErrors(['permissions' => self::REFUSAL]);
        $this->assertFalse(RoleTemplate::query()->where('key', 'pelanggan_staf')->exists());

        $this->asOperator()->put(route('platform.role-templates.update', $template), ['name' => $template->name, 'permissions' => [...$held, 'app.web']])
            ->assertSessionHasErrors(['permissions' => self::REFUSAL]);
        $this->assertSame($held, $this->permissionsOf($template));

        $this->asOperator()->post(route('platform.companies.roles.store', $this->alpha['root']), [
            'company_id' => $this->alpha['root']->public_id,
            'name' => 'Pelanggan Staf',
            'permissions' => ['portal.access', 'app.web'],
        ])->assertSessionHasErrors(['permissions' => self::REFUSAL]);
        $this->assertFalse(Role::withoutCompany()->where('name', 'Pelanggan Staf')->exists());

        $this->asOperator()->put(route('platform.companies.roles.update', [$this->alpha['root'], $customer->public_id]), [
            'name' => $customer->name,
            'permissions' => [...$heldByRole, 'app.web'],
            OptimisticLock::FIELD => OptimisticLock::version($customer),
        ])->assertSessionHasErrors(['permissions' => self::REFUSAL]);
        $this->assertSame($heldByRole, $this->permissionsOf($customer));

        // The operator's own edits that keep the audiences apart still go through.
        $this->asOperator()->put(route('platform.role-templates.update', $template), ['name' => $template->name, 'permissions' => [...$held, 'ticket.update']])
            ->assertRedirect(route('platform.role-templates.index'));
        $this->assertContains('ticket.update', $this->permissionsOf($template));
    }
}
