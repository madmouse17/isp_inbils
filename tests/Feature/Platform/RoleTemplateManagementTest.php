<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\RoleTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Role templates are the roles every NEW company starts from (ADR-004): the platform panel is their only writer. Every
 * write needs a fresh platform password confirmation, is audited in the `platform` log with the permission diff, and
 * never reaches a company that already has its roles (copy once, never resync).
 */
class RoleTemplateManagementTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private function template(string $key): RoleTemplate
    {
        return RoleTemplate::query()->where('key', $key)->firstOrFail();
    }

    private function custom(string $name = 'Supervisor', string ...$permissions): RoleTemplate
    {
        $template = new RoleTemplate();
        $template->forceFill(['key' => 'supervisor', 'name' => $name])->save();
        $template->permissions()->sync(Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions)->pluck('id')->all());

        return $template;
    }

    /** @return list<string> */
    private function permissionsOf(RoleTemplate $template): array
    {
        return $template->permissions()->pluck('name')->sort()->values()->all();
    }

    public function test_only_a_platform_admin_with_a_verified_second_factor_reaches_the_template_pages(): void
    {
        $template = $this->custom();
        $urls = [route('platform.role-templates.index'), route('platform.role-templates.create'), route('platform.role-templates.edit', $template)];
        $tenant = $this->buildTenant('ALP', 'Alpha Net');

        // One phase after the other: a platform request leaves its session in the test's store, so the operator comes last.
        foreach ($urls as $url) {
            $this->asTenantVisitor()->get($url)->assertRedirect(route('platform.login'));
        }

        foreach ($urls as $url) {
            $this->actingAs($tenant['admin'])->get($url)->assertRedirect(route('platform.login'));
        }

        foreach ($urls as $url) {
            $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null])->get($url)->assertRedirect(route('platform.login'));
        }

        foreach ($urls as $url) {
            $this->asOperator(false)->get($url)->assertOk();
        }
    }

    public function test_the_index_lists_every_template_in_display_order_with_its_permission_count_and_deletability(): void
    {
        $this->custom('Supervisor', 'customer.view', 'ticket.view');

        $this->asOperator(false)->get(route('platform.role-templates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/RoleTemplates/Index', false)
                ->has('templates.data', 9)
                ->where('templates.data.0.key', 'admin')
                ->where('templates.data.0.deletable', false)
                ->where('templates.data.0.permissions_count', $this->template('admin')->permissions()->count())
                ->where('templates.data', fn ($rows): bool => collect($rows)->firstWhere('key', 'customer')['deletable'] === false)
                ->where('templates.data.8', fn ($row): bool => $row['key'] === 'supervisor' && $row['deletable'] === true && $row['permissions_count'] === 2));
    }

    public function test_the_create_page_lists_every_web_permission(): void
    {
        $first = Permission::query()->where('guard_name', 'web')->orderBy('name')->firstOrFail();

        $this->asOperator(false)->get(route('platform.role-templates.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/RoleTemplates/Create', false)
                ->has('permissions.data', Permission::query()->where('guard_name', 'web')->count())
                ->where('permissions.data.0.name', $first->name));
    }

    public function test_the_edit_page_carries_the_template_and_its_permissions(): void
    {
        $template = $this->custom('Supervisor', 'ticket.view', 'customer.view');

        $this->asOperator(false)->get(route('platform.role-templates.edit', $template))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/RoleTemplates/Edit', false)
                ->where('template.data', [
                    'id' => $template->public_id,
                    'key' => 'supervisor',
                    'name' => 'Supervisor',
                    'permissions' => ['customer.view', 'ticket.view'],
                    'deletable' => true,
                ])
                ->has('permissions.data'));
    }

    public function test_every_template_write_needs_a_fresh_password_confirmation(): void
    {
        $template = $this->custom('Supervisor', 'customer.view');
        $writes = [
            fn () => $this->post(route('platform.role-templates.store'), ['name' => 'Rogue', 'permissions' => ['customer.view']]),
            fn () => $this->put(route('platform.role-templates.update', $template), ['name' => 'Supervisor', 'permissions' => ['customer.view', 'ticket.view']]),
            fn () => $this->delete(route('platform.role-templates.destroy', $template)),
        ];

        foreach ([[], $this->platformPasswordConfirmed(301)] as $session) {
            foreach ($writes as $write) {
                $this->signInPlatformAdmin($this->operator, $session);
                $write()->assertRedirect(route('platform.password.confirm'));
            }
        }

        $this->assertFalse(RoleTemplate::query()->where('name', 'Rogue')->exists());
        $this->assertSame(['customer.view'], $this->permissionsOf($template->fresh()));
        $this->assertSame(0, Activity::query()->where('description', 'like', 'role_template.%')->count());
    }

    public function test_a_template_is_created_with_a_derived_key_and_the_write_is_audited(): void
    {
        $this->asOperator()
            ->post(route('platform.role-templates.store'), ['name' => 'Supervisor Lapangan', 'permissions' => ['ticket.view', 'customer.view']])
            ->assertRedirect(route('platform.role-templates.index'));

        $template = $this->template('supervisor_lapangan');
        $this->assertSame('Supervisor Lapangan', $template->name);
        $this->assertSame(['customer.view', 'ticket.view'], $this->permissionsOf($template));

        $row = Activity::query()->where('description', 'role_template.created')->sole();
        $this->assertSame('platform', $row->log_name);
        $this->assertSame(PlatformAdmin::class, $row->causer_type);
        $this->assertSame($this->operator->id, (int) $row->causer_id);
        $this->assertSame(RoleTemplate::class, $row->subject_type);
        $this->assertSame($template->id, (int) $row->subject_id);
        $this->assertNull($row->company_id);
        $this->assertEquals([
            'key' => 'supervisor_lapangan',
            'name' => 'Supervisor Lapangan',
            'permissions' => ['customer.view', 'ticket.view'],
        ], $row->properties->toArray());
    }

    public function test_a_template_without_permissions_can_be_created(): void
    {
        $this->asOperator()->post(route('platform.role-templates.store'), ['name' => 'Kosong'])->assertRedirect(route('platform.role-templates.index'));

        $this->assertSame([], $this->permissionsOf($this->template('kosong')));
    }

    public function test_the_name_and_permissions_are_validated(): void
    {
        $this->custom('Supervisor');
        Permission::create(['name' => 'api.only', 'guard_name' => 'api']);
        $cases = [
            'missing name' => [['permissions' => []], 'name'],
            'too long' => [['name' => str_repeat('x', 256)], 'name'],
            'name taken' => [['name' => 'Supervisor'], 'name'],
            'name taken in another case' => [['name' => 'supervisor'], 'name'],
            'a seeded name' => [['name' => 'Admin'], 'name'],
            'a key taken by another name' => [['name' => 'SUPERVISOR!'], 'name'],
            'no letter or digit' => [['name' => '!!!'], 'name'],
            'unknown permission' => [['name' => 'Fresh', 'permissions' => ['no.such.permission']], 'permissions.0'],
            'not a list' => [['name' => 'Fresh', 'permissions' => 'customer.view'], 'permissions'],
            'permission of another guard' => [['name' => 'Fresh', 'permissions' => ['api.only']], 'permissions.0'],
            'a permission twice' => [['name' => 'Fresh', 'permissions' => ['customer.view', 'customer.view']], 'permissions.0'],
            'a malformed entry' => [['name' => 'Fresh', 'permissions' => [['inventory.request.approve'], 'inventory.request.prepare']], 'permissions.0'],
        ];

        foreach ($cases as $label => [$payload, $field]) {
            $this->asOperator()->post(route('platform.role-templates.store'), $payload)->assertSessionHasErrors($field);
            $this->assertFalse(RoleTemplate::query()->where('key', 'fresh')->exists(), $label);
        }

        $this->assertSame(9, RoleTemplate::query()->count());
    }

    public function test_a_template_is_renamed_and_its_permissions_changed_with_an_audit_of_the_diff(): void
    {
        $template = $this->custom('Supervisor', 'customer.view');

        $this->asOperator()
            ->put(route('platform.role-templates.update', $template), ['name' => 'Penyelia', 'permissions' => ['ticket.view', 'customer.view']])
            ->assertRedirect(route('platform.role-templates.index'));

        $template->refresh();
        $this->assertSame('Penyelia', $template->name);
        $this->assertSame('supervisor', $template->key, 'the key never changes');
        $this->assertSame(['customer.view', 'ticket.view'], $this->permissionsOf($template));

        $row = Activity::query()->where('description', 'role_template.updated')->sole();
        $this->assertSame('platform', $row->log_name);
        $this->assertSame($this->operator->id, (int) $row->causer_id);
        $this->assertEquals([
            'key' => 'supervisor',
            'name_before' => 'Supervisor',
            'name_after' => 'Penyelia',
            'permissions_added' => ['ticket.view'],
            'permissions_removed' => [],
        ], $row->properties->toArray());
    }

    public function test_a_save_that_changes_nothing_writes_no_audit_row_and_may_keep_its_own_name(): void
    {
        $template = $this->custom('Supervisor', 'customer.view');

        $this->asOperator()
            ->put(route('platform.role-templates.update', $template), ['name' => 'Supervisor', 'permissions' => ['customer.view']])
            ->assertRedirect(route('platform.role-templates.index'));

        $this->assertSame(0, Activity::query()->where('description', 'role_template.updated')->count());
    }

    public function test_a_template_name_cannot_take_the_name_of_another_template(): void
    {
        $template = $this->custom('Supervisor');

        $this->asOperator()->put(route('platform.role-templates.update', $template), ['name' => 'Admin'])->assertSessionHasErrors('name');

        $this->assertSame('Supervisor', $template->fresh()->name);
    }

    public function test_the_admin_template_must_keep_roles_manage_and_web_access(): void
    {
        $admin = $this->template('admin');
        $before = $this->permissionsOf($admin);
        $without = fn (string ...$drop): array => array_values(array_diff($before, $drop));

        foreach ([['roles.manage'], ['app.web'], ['roles.manage', 'app.web']] as $drop) {
            $this->asOperator()
                ->put(route('platform.role-templates.update', $admin), ['name' => 'Admin', 'permissions' => $without(...$drop)])
                ->assertSessionHasErrors('permissions');
        }
        $this->asOperator()->put(route('platform.role-templates.update', $admin), ['name' => 'Admin', 'permissions' => []])->assertSessionHasErrors('permissions');

        $this->assertSame($before, $this->permissionsOf($admin->fresh()));
        $this->assertSame(0, Activity::query()->where('description', 'role_template.updated')->count());

        $this->asOperator()
            ->put(route('platform.role-templates.update', $admin), ['name' => 'Admin', 'permissions' => $without('report.audit.view')])
            ->assertRedirect(route('platform.role-templates.index'));
        $this->assertNotContains('report.audit.view', $this->permissionsOf($admin->fresh()));

        $other = $this->template('manager');
        $this->asOperator()
            ->put(route('platform.role-templates.update', $other), ['name' => $other->name, 'permissions' => ['dashboard.view']])
            ->assertRedirect(route('platform.role-templates.index'));
    }

    public function test_the_admin_and_customer_templates_cannot_be_deleted_and_another_one_can(): void
    {
        foreach (['admin', 'customer'] as $key) {
            $this->asOperator()->delete(route('platform.role-templates.destroy', $this->template($key)))
                ->assertSessionHasErrors(['template' => 'Templat ini dipakai saat perusahaan dibuat.']);
            $this->assertTrue(RoleTemplate::query()->where('key', $key)->exists(), $key);
        }
        $this->assertSame(0, Activity::query()->where('description', 'role_template.deleted')->count());

        $template = $this->custom('Supervisor', 'customer.view');

        $this->asOperator()->delete(route('platform.role-templates.destroy', $template))->assertRedirect(route('platform.role-templates.index'));

        $this->assertFalse(RoleTemplate::query()->where('key', 'supervisor')->exists());
        $row = Activity::query()->where('description', 'role_template.deleted')->sole();
        $this->assertSame('platform', $row->log_name);
        $this->assertEquals(['key' => 'supervisor', 'name' => 'Supervisor', 'permissions' => ['customer.view']], $row->properties->toArray());
    }

    public function test_a_set_that_breaks_the_separation_of_duties_is_refused_unless_it_holds_roles_manage(): void
    {
        $approve = 'Pemisahan tugas (persetujuan permintaan barang vs pelaksanaan gudang): inventory.request.approve tidak boleh digabung dengan inventory.request.prepare.';

        $this->asOperator()
            ->post(route('platform.role-templates.store'), ['name' => 'Gudang Super', 'permissions' => ['inventory.request.approve', 'inventory.request.prepare']])
            ->assertSessionHasErrors(['permissions' => $approve]);
        $this->assertFalse(RoleTemplate::query()->where('key', 'gudang_super')->exists());

        $ops = $this->custom('Gudang Ops', 'inventory.request.prepare');
        $this->asOperator()
            ->put(route('platform.role-templates.update', $ops), ['name' => 'Gudang Ops', 'permissions' => ['inventory.request.prepare', 'inventory.request.approve']])
            ->assertSessionHasErrors(['permissions' => $approve]);
        $this->assertSame(['inventory.request.prepare'], $this->permissionsOf($ops->fresh()));

        $this->asOperator()
            ->post(route('platform.role-templates.store'), ['name' => 'Pemilik', 'permissions' => ['roles.manage', 'inventory.request.approve', 'inventory.request.prepare', 'inventory.stock.adjust']])
            ->assertRedirect(route('platform.role-templates.index'));
        $this->assertContains('inventory.request.approve', $this->permissionsOf($this->template('pemilik')));
    }

    public function test_editing_a_template_never_changes_a_company_that_already_has_its_roles_but_a_new_company_gets_the_edit(): void
    {
        $existing = Company::factory()->create();
        $staffBefore = $this->companyRoles($existing)['staff'];
        $before = $staffBefore->permissions()->pluck('name')->sort()->values()->all();
        $this->assertNotContains('report.audit.view', $before);

        $this->asOperator()
            ->put(route('platform.role-templates.update', $this->template('staff')), ['name' => 'Staf Baru', 'permissions' => ['dashboard.view', 'report.audit.view']])
            ->assertRedirect(route('platform.role-templates.index'));

        $staffAfter = $this->companyRoles($existing)['staff']->fresh();
        $this->assertSame($staffBefore->name, $staffAfter->name, 'the company copy keeps its name');
        $this->assertSame($before, $staffAfter->permissions()->pluck('name')->sort()->values()->all(), 'and its permissions');

        $created = Company::factory()->create();
        $copy = $this->companyRoles($created)['staff'];
        $this->assertSame('Staf Baru', $copy->name);
        $this->assertSame(['dashboard.view', 'report.audit.view'], $copy->permissions()->pluck('name')->sort()->values()->all());
    }

    public function test_a_template_change_is_a_platform_row_with_no_company_and_no_support_row(): void
    {
        $this->asOperator()->post(route('platform.role-templates.store'), ['name' => 'Baru'])->assertRedirect();

        $this->assertSame(1, Activity::query()->where('description', 'role_template.created')->where('log_name', 'platform')->whereNull('company_id')->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'support')->count(), 'a template belongs to no tenant');
    }
}
