<?php

namespace Tests\Feature\Roles;

use App\Models\Core\Company;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Support\PermissionCatalogue;
use App\Support\SodConflicts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The permission matrix of the eight default role templates (ADR-004). The templates exist after `migrate`: the
 * role-template migration installs them from PermissionCatalogue, so nothing here seeds.
 */
class TemplatePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function templateKeys(): iterable
    {
        foreach (PermissionCatalogue::templates() as $template) {
            yield $template['key'] => [$template['key']];
        }
    }

    #[DataProvider('templateKeys')]
    public function test_template_holds_exactly_its_catalogue_set(string $key): void
    {
        $default = collect(PermissionCatalogue::templates())->firstWhere('key', $key);
        $template = RoleTemplate::query()->with('permissions')->where('key', $key)->firstOrFail();

        $this->assertSame($default['name'], $template->name);
        $this->assertEqualsCanonicalizing($default['permissions'], $template->permissions->pluck('name')->all());
    }

    public function test_the_eight_defaults_exist_in_display_order(): void
    {
        $this->assertSame(
            ['admin', 'manager', 'noc', 'technician', 'warehouse', 'finance', 'staff', 'customer'],
            RoleTemplate::query()->orderBy('id')->pluck('key')->all(),
        );
        $this->assertSame(
            ['Admin', 'Manajer', 'NOC & Layanan Pelanggan', 'Teknisi', 'Gudang', 'Keuangan', 'Staf', 'Pelanggan'],
            RoleTemplate::query()->orderBy('id')->pluck('name')->all(),
        );
    }

    public function test_only_the_admin_template_holds_roles_manage_and_users_manage(): void
    {
        foreach (PermissionCatalogue::templates() as $template) {
            foreach (['roles.manage', 'users.manage'] as $permission) {
                $this->assertSame($template['key'] === 'admin', in_array($permission, $template['permissions'], true), "{$template['key']} and {$permission}");
            }
        }
    }

    public function test_no_template_but_admin_breaks_separation_of_duties(): void
    {
        foreach (PermissionCatalogue::templates() as $template) {
            $violations = SodConflicts::violations($template['permissions']);

            if ($template['key'] === 'admin') {
                // Admin is the break-glass set: the roles.manage exemption of the role requests exists for it.
                $this->assertNotSame([], $violations);

                continue;
            }

            $this->assertSame([], $violations, $template['key']);
        }
    }

    public function test_noc_holds_registration_tickets_announcements_network_resync_and_isolir_without_delete_billing_writes_or_administration(): void
    {
        $noc = $this->permissionsOf('noc');

        foreach ([
            // the onboarding gate: create, address, subscription.manage and spk.create
            'customer.create', 'customer.address.manage', 'customer.subscription.manage', 'spk.create',
            'ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign', 'ticket.reassign', 'ticket.close', 'ticket.spawn_spk',
            'ticket.comment.create', 'ticket.comment.internal', 'ticket.attachment.upload', 'ticket.attachment.view',
            'announcement.manage',
            'network_asset.view', 'network_asset.nas.view', 'network_asset.account.view', 'ipam.view',
            'network_asset.account.manage',
            'customer.subscription.block',
            'billing.view', 'report.view', 'report.sla.view',
            'app.web', 'evaluation.evaluable',
        ] as $permission) {
            $this->assertContains($permission, $noc);
        }

        foreach ($noc as $permission) {
            $this->assertDoesNotMatchRegularExpression('/\.delete$/', $permission, 'no delete');
            $this->assertDoesNotMatchRegularExpression('/^(users|roles|company)\./', $permission, 'no administration');
        }

        foreach (['billing.create', 'billing.update', 'billing.send', 'billing.payment.record', 'billing.payment.reverse', 'billing.cancel', 'billing.manage'] as $permission) {
            $this->assertNotContains($permission, $noc, 'no billing write');
        }
    }

    public function test_manajer_and_noc_hold_ticket_assign_and_keuangan_holds_payment_reverse(): void
    {
        $this->assertContains('ticket.assign', $this->permissionsOf('manager'));
        $this->assertContains('ticket.assign', $this->permissionsOf('noc'));
        $this->assertContains('billing.payment.reverse', $this->permissionsOf('finance'));
        $this->assertNotContains('billing.cancel', $this->permissionsOf('finance'));
    }

    public function test_staff_template_lacks_customer_create(): void
    {
        $this->assertNotContains('customer.create', $this->permissionsOf('staff'));
        $this->assertContains('customer.create', $this->permissionsOf('manager'));
    }

    public function test_only_teknisi_holds_app_technician_and_only_pelanggan_holds_portal_permissions(): void
    {
        foreach (PermissionCatalogue::templates() as $template) {
            $this->assertSame($template['key'] === 'technician', in_array('app.technician', $template['permissions'], true), $template['key']);

            foreach (['portal.access', 'portal.invoice.view', 'portal.invoice.pay'] as $permission) {
                $this->assertSame($template['key'] === 'customer', in_array($permission, $template['permissions'], true), "{$template['key']} and {$permission}");
            }
        }
    }

    public function test_every_template_except_teknisi_and_pelanggan_opens_the_web(): void
    {
        foreach (PermissionCatalogue::templates() as $template) {
            $this->assertSame(
                ! in_array($template['key'], ['technician', 'customer'], true),
                in_array(PermissionCatalogue::WEB, $template['permissions'], true),
                $template['key'],
            );
        }
    }

    public function test_every_template_permission_exists_in_the_catalogue(): void
    {
        foreach (PermissionCatalogue::templates() as $template) {
            $this->assertSame([], array_values(array_diff($template['permissions'], PermissionCatalogue::ALL)), $template['key']);
            $this->assertSame($template['permissions'], array_values(array_unique($template['permissions'])), "{$template['key']} lists a permission twice");
        }
    }

    public function test_the_catalogue_lists_every_permission_once_and_each_exists_after_migrate(): void
    {
        $this->assertSame(PermissionCatalogue::ALL, array_values(array_unique(PermissionCatalogue::ALL)));
        $this->assertSame([], array_values(array_diff(PermissionCatalogue::ALL, DB::table('permissions')->where('guard_name', 'web')->pluck('name')->all())));

        foreach (['app.web', 'app.technician', 'portal.access', 'evaluation.evaluable', 'report.technician.team', 'roles.manage'] as $permission) {
            $this->assertContains($permission, PermissionCatalogue::ALL);
        }

        $this->assertSame([], array_values(array_diff(PermissionCatalogue::UNGUARDED, PermissionCatalogue::ALL)));
    }

    public function test_the_admin_template_is_the_catalogue_without_unguarded(): void
    {
        $this->assertSame(
            array_values(array_diff(PermissionCatalogue::ALL, PermissionCatalogue::UNGUARDED)),
            $this->permissionsOf('admin'),
        );
        $this->assertContains('roles.manage', $this->permissionsOf('admin'));
        $this->assertContains('app.web', $this->permissionsOf('admin'));
    }

    public function test_guarded_drops_the_markers_and_keeps_the_order(): void
    {
        $this->assertSame(
            ['ticket.view', 'roles.manage'],
            PermissionCatalogue::guarded(['app.technician', 'ticket.view', 'portal.access', 'roles.manage', 'portal.invoice.pay', 'evaluation.evaluable']),
        );
    }

    public function test_a_company_copy_of_each_template_holds_the_template_set(): void
    {
        $company = Company::factory()->create();
        $roles = $this->companyRoles($company);

        $this->assertEqualsCanonicalizing(array_column(PermissionCatalogue::templates(), 'key'), array_keys($roles));

        foreach (PermissionCatalogue::templates() as $template) {
            /** @var Role $role */
            $role = $roles[$template['key']];

            $this->assertSame($template['name'], $role->name);
            $this->assertEqualsCanonicalizing($template['permissions'], $role->permissions()->pluck('name')->all(), $template['key']);
        }
    }

    public function test_release_adds_a_permission_to_the_templates_and_their_company_copies_only(): void
    {
        $alpha = Company::factory()->create();
        $beta = Company::factory()->create();
        $alphaRoles = $this->companyRoles($alpha);
        $betaRoles = $this->companyRoles($beta);
        // A role a company made itself is its own: a release never reaches it.
        $custom = $this->companyRole($alpha, 'Penyelia', ['customer.view']);

        PermissionCatalogue::release('billing.refund.review', ['finance', 'admin']);
        PermissionCatalogue::release('billing.refund.review', ['finance', 'admin']);

        $this->assertSame(1, DB::table('permissions')->where('name', 'billing.refund.review')->count());

        foreach (['finance', 'admin'] as $key) {
            $this->assertTrue(RoleTemplate::query()->where('key', $key)->firstOrFail()->permissions()->where('name', 'billing.refund.review')->exists(), "template {$key}");

            foreach ([$alphaRoles, $betaRoles] as $roles) {
                $this->assertTrue($roles[$key]->permissions()->where('name', 'billing.refund.review')->exists(), "copy of {$key}");
            }
        }

        foreach (['manager', 'staff', 'customer'] as $key) {
            $this->assertFalse(RoleTemplate::query()->where('key', $key)->firstOrFail()->permissions()->where('name', 'billing.refund.review')->exists(), "template {$key}");
            $this->assertFalse($alphaRoles[$key]->permissions()->where('name', 'billing.refund.review')->exists(), "copy of {$key}");
        }

        $this->assertFalse($custom->permissions()->where('name', 'billing.refund.review')->exists());
        $this->assertSame(4, DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('permissions.name', 'billing.refund.review')->count(), 'two companies, two copies each');
    }

    /** @return list<string> the permissions of a default template as the database holds them, in catalogue order */
    private function permissionsOf(string $key): array
    {
        return RoleTemplate::query()->with('permissions')->where('key', $key)->firstOrFail()->permissions->pluck('name')
            ->sortBy(fn (string $name): int => (int) array_search($name, PermissionCatalogue::ALL, true))->values()->all();
    }
}
