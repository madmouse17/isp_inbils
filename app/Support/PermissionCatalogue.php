<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every permission the code knows, in one place (ADR-004): companies compose roles from this catalogue and never invent
 * permissions. It also holds the default role templates that a new company copies once.
 *
 * Writes go through DB::table(), not the models: migrations and seeders run it before any company exists, and the
 * role model answers for one company only.
 */
final class PermissionCatalogue
{
    /**
     * Every permission name. One flat list of string literals on purpose: the role editor self-check
     * (permissionGroups.selfcheck.mjs) reads it as text.
     *
     * @var list<string>
     */
    public const ALL = [
        // Akses aplikasi
        'app.web',
        'app.technician',

        // Core admin
        'users.manage',
        'roles.manage',
        'roles.export',
        'company.manage',
        // Configure the Midtrans, WhatsApp, e-mail and RADIUS accounts of the company (admin only).
        'company.integrations.manage',
        // Reset the two-factor authentication of another user (admin only).
        'users.two_factor.reset',
        // Switch the active company: needs a membership of the target company on top (CompanyPolicy).
        'company.switch',
        'system.setting',
        'dashboard.view',
        // Developer gallery is intentionally admin-only (fail-closed).
        'developer.components.view',
        'organization.view',
        'organization.manage',
        'organization.export',
        'employee.view',
        'employee.manage',
        'employee.export',
        'vehicle.view',
        'vehicle.manage',
        'vehicle.export',

        // Customer
        'customer.view',
        'customer.create',
        'customer.update',
        'customer.delete',
        'customer.address.manage',
        'customer.subscription.view',
        'customer.subscription.manage',
        'customer.subscription.activate',
        'customer.subscription.suspend',
        'customer.subscription.reactivate',
        'customer.subscription.block',
        'customer.subscription.terminate',
        'customer.export',
        'customer.manage',
        // Sales attribution (customers.acquired_by_user_id): assign sets it from Unassigned,
        // correct changes or clears it with a reason. Seen only by holders of either.
        'customer.sales.assign',
        'customer.sales.correct',

        'service.view',
        'service.create',
        'service.update',
        'service.delete',
        'service.export',
        'service.manage',
        'announcement.manage',

        'system.document.export',
        'customer.contact.export',
        'customer.subscription.export',
        'customer.address.export',

        'network_asset.view',
        'network_asset.create',
        'network_asset.update',
        'network_asset.delete',
        'network_asset.install',
        'network_asset.remove',
        'network_asset.maintenance',
        'network_asset.repair',
        'network_asset.retire',
        'network_asset.export',
        'network_asset.manage',
        // RADIUS access (NAS routers and network accounts): manage = admin only, reveal is audited.
        'network_asset.nas.view',
        'network_asset.nas.manage',
        'network_asset.account.view',
        'network_asset.account.manage',
        'network_asset.account.reveal',

        // IP address management and the optical distribution network register
        'ipam.view',
        'ipam.manage',
        'ipam.assign',
        'odn.view',
        'odn.manage',
        'odn.assign',

        'location.view',
        'location.create',
        'location.update',
        'location.delete',
        'location.move',
        'location.manage',

        'inventory.view',
        'inventory.create',
        'inventory.update',
        'inventory.delete',
        'inventory.export',
        'inventory.manage',
        'inventory.stock.receive',
        'inventory.stock.issue',
        'inventory.stock.transfer',
        'inventory.stock.adjust',
        'inventory.stock.adjust.request',
        'inventory.stock.adjust.approve',

        'inventory.request.view.own',
        'inventory.request.view.all',
        'inventory.request.create',
        'inventory.request.cancel.own',
        'inventory.request.approve',
        'inventory.request.reject',
        'inventory.request.prepare',
        'inventory.request.issue',
        'inventory.request.audit',
        'inventory.return.view.own',
        'inventory.return.view.all',
        'inventory.return.declare',
        'inventory.return.receive',
        'inventory.return.resolve',

        'spk.view',
        'spk.create',
        'spk.update',
        'spk.delete',
        'spk.assign',
        'spk.reassign',
        'spk.start',
        'spk.submit',
        'spk.approve',
        'spk.reject',
        'spk.cancel',
        'spk.evidence.upload',
        'spk.evidence.view',
        'spk.export',
        'spk.manage',

        'billing.view',
        'billing.create',
        'billing.update',
        'billing.delete',
        'billing.send',
        'billing.payment.record',
        'billing.payment.reverse',
        'billing.cancel',
        'billing.suspend',
        'billing.reactivate',
        'billing.pdf.view',
        'billing.export',
        'billing.manage',

        'ticket.view',
        'ticket.create',
        'ticket.update',
        'ticket.delete',
        'ticket.assign',
        'ticket.reassign',
        'ticket.start',
        'ticket.resolve',
        'ticket.close',
        'ticket.reopen',
        'ticket.comment.create',
        'ticket.comment.internal',
        'ticket.attachment.upload',
        'ticket.attachment.view',
        'ticket.spawn_spk',
        'ticket.export',
        'ticket.manage',
        'ticket.category.manage',

        'evaluation.view',
        'evaluation.create',
        'evaluation.update',
        'evaluation.delete',
        'evaluation.view.own',
        'evaluation.customer.submit',
        'evaluation.evaluable',
        'evaluation.export',
        'evaluation.manage',

        'portal.access',
        'portal.invoice.view',
        'portal.invoice.pay',

        'report.view',
        'report.business.view',
        'report.asset.view',
        'report.sla.view',
        'report.stock_card.view',
        'report.dictionary.view',
        'report.sales.view',
        'report.technician',
        'report.technician.team',
        'report.manage',
        'report.audit.view',
        'report.export',
        // Consolidated Business metrics of a Head company over its subsidiaries. Admin only.
        'report.consolidated.view',
    ];

    /** Who may open the web back office: every `/admin` route (RedirectAppOnlyRoles). */
    public const WEB = 'app.web';

    /** A field technician: the subject of the technician report and its self scope, and the app the "use the app" page names. */
    public const TECHNICIAN_APP = 'app.technician';

    /** A customer account: the customer app and the web portal, together with the `customers.user_id` link. */
    public const PORTAL = 'portal.access';

    /** An employee who can be evaluated. */
    public const EVALUABLE = 'evaluation.evaluable';

    /** The organization-subtree scope of the technician report. */
    public const TECHNICIAN_TEAM_REPORT = 'report.technician.team';

    /** What makes a company administrator: whoever holds it manages the company's roles. */
    public const ADMINISTRATION = 'roles.manage';

    /**
     * Permissions that classify a person or belong to customers only. None of them opens anything by itself, anyone
     * who may edit roles may place them, they are exempt from "hold it yourself" and the Admin template leaves them out.
     * Every other permission is guarded.
     *
     * @var list<string>
     */
    public const UNGUARDED = ['app.technician', 'evaluation.evaluable', 'portal.access', 'portal.invoice.view', 'portal.invoice.pay'];

    /**
     * @param  list<string>  $permissions
     * @return list<string> the guarded ones, in the given order
     */
    public static function guarded(array $permissions): array
    {
        return array_values(array_diff($permissions, self::UNGUARDED));
    }

    /**
     * The eight default templates, in display order. The first administrator of a new company gets `admin`, and
     * every customer account gets `customer`.
     *
     * @return list<array{key: string, name: string, permissions: list<string>}>
     */
    public static function templates(): array
    {
        return [
            [
                'key' => 'admin',
                'name' => 'Admin',
                'permissions' => self::guarded(self::ALL),
            ],
            [
                'key' => 'manager',
                'name' => 'Manajer',
                'permissions' => [
                    'app.web', 'report.technician.team',

                    'customer.view', 'customer.create', 'customer.update',
                    'customer.address.manage',
                    'customer.subscription.view', 'customer.subscription.manage',
                    'customer.subscription.activate', 'customer.subscription.suspend',
                    'customer.subscription.reactivate', 'customer.subscription.terminate',
                    'customer.subscription.block',
                    'customer.export', 'customer.manage',
                    'customer.sales.assign', 'customer.sales.correct',

                    'service.view', 'service.create', 'service.update', 'service.manage',
                    'announcement.manage',
                    'dashboard.view',

                    'network_asset.view', 'network_asset.create', 'network_asset.update',
                    'network_asset.install', 'network_asset.remove',
                    'network_asset.maintenance', 'network_asset.repair', 'network_asset.retire',
                    'network_asset.export', 'network_asset.manage',
                    'network_asset.nas.view',
                    'network_asset.account.view', 'network_asset.account.manage', 'network_asset.account.reveal',
                    'ipam.view', 'ipam.manage', 'ipam.assign',
                    'odn.view', 'odn.manage', 'odn.assign',

                    'location.view', 'location.create', 'location.update',
                    'location.move', 'location.manage',

                    'inventory.view', 'inventory.create', 'inventory.update',
                    'inventory.export', 'inventory.manage',
                    'inventory.stock.adjust.approve',
                    'inventory.request.view.all', 'inventory.request.approve', 'inventory.request.reject',
                    'inventory.request.audit',
                    'inventory.return.view.all', 'inventory.return.receive', 'inventory.return.resolve',

                    'spk.view', 'spk.create', 'spk.update',
                    'spk.assign', 'spk.reassign', 'spk.approve', 'spk.reject', 'spk.cancel',
                    'spk.export', 'spk.manage',

                    'billing.view', 'billing.create', 'billing.update',
                    'billing.send', 'billing.payment.record', 'billing.payment.reverse',
                    'billing.cancel', 'billing.suspend', 'billing.reactivate',
                    'billing.pdf.view', 'billing.export', 'billing.manage',

                    'ticket.view', 'ticket.create', 'ticket.update',
                    'ticket.assign', 'ticket.reassign', 'ticket.close', 'ticket.spawn_spk',
                    'ticket.export', 'ticket.manage', 'ticket.category.manage',

                    'evaluation.view', 'evaluation.create', 'evaluation.update',

                    'report.view', 'report.technician',
                    'report.business.view', 'report.asset.view', 'report.sla.view',
                    'report.stock_card.view', 'report.dictionary.view', 'report.sales.view',
                    'report.export',
                    'company.switch',
                ],
            ],
            [
                'key' => 'noc',
                'name' => 'NOC & Layanan Pelanggan',
                'permissions' => [
                    'app.web', 'dashboard.view',
                    // Customer registration: the onboarding gate needs create, address, subscription.manage and spk.create.
                    'customer.view', 'customer.create', 'customer.update', 'customer.address.manage',
                    'customer.subscription.view', 'customer.subscription.manage', 'customer.sales.assign',
                    'service.view', 'location.view', 'odn.view', 'odn.assign',
                    'spk.view', 'spk.create',
                    // Complaints and tickets, including dispatch notices (ticket.assign).
                    'ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign', 'ticket.reassign', 'ticket.close',
                    'ticket.comment.create', 'ticket.comment.internal',
                    'ticket.attachment.upload', 'ticket.attachment.view', 'ticket.spawn_spk',
                    'announcement.manage',
                    // Network view and trace, RADIUS resync (the gate of the resync action) and manual isolir.
                    'network_asset.view', 'network_asset.nas.view', 'network_asset.account.view', 'ipam.view',
                    'network_asset.account.manage',
                    'customer.subscription.block',
                    'billing.view',
                    'report.view', 'report.sla.view',
                    'evaluation.evaluable',
                ],
            ],
            [
                'key' => 'technician',
                'name' => 'Teknisi',
                'permissions' => [
                    'app.technician', 'evaluation.evaluable',

                    'dashboard.view',
                    'customer.view',
                    'network_asset.view',
                    'ipam.view', 'odn.view',
                    'location.view',
                    'inventory.view', 'inventory.stock.issue',
                    'inventory.request.view.own', 'inventory.request.create', 'inventory.request.cancel.own',
                    'inventory.return.view.own', 'inventory.return.declare',
                    'spk.view', 'spk.start', 'spk.submit',
                    'spk.evidence.upload', 'spk.evidence.view',
                    'ticket.view', 'ticket.create', 'ticket.start', 'ticket.resolve',
                    'ticket.comment.create', 'ticket.attachment.upload',
                    'evaluation.view.own',
                    'report.technician',
                ],
            ],
            [
                'key' => 'warehouse',
                'name' => 'Gudang',
                // The execution side only: approval stays with the manager (separation of duties).
                'permissions' => [
                    'app.web', 'dashboard.view', 'location.view',
                    'inventory.view', 'inventory.create', 'inventory.update', 'inventory.export',
                    'inventory.stock.receive', 'inventory.stock.issue', 'inventory.stock.transfer',
                    'inventory.stock.adjust.request',
                    'inventory.request.view.all', 'inventory.request.prepare', 'inventory.request.issue',
                    'inventory.return.view.all', 'inventory.return.receive',
                    'spk.view',
                    'report.view', 'report.stock_card.view',
                ],
            ],
            [
                'key' => 'finance',
                'name' => 'Keuangan',
                // payment.reverse marks refunds; the same-actor guard keeps the reverser apart from the recorder. No billing.cancel.
                'permissions' => [
                    'app.web', 'dashboard.view',
                    'customer.view', 'customer.subscription.view',
                    'billing.view', 'billing.create', 'billing.update', 'billing.send',
                    'billing.payment.record', 'billing.payment.reverse',
                    'billing.pdf.view', 'billing.export',
                    'report.view', 'report.business.view',
                ],
            ],
            [
                'key' => 'staff',
                'name' => 'Staf',
                'permissions' => [
                    'app.web', 'evaluation.evaluable',

                    'dashboard.view',
                    'customer.view', 'customer.update',
                    'customer.address.manage',
                    'customer.subscription.view',
                    'customer.sales.assign',
                    'service.view',
                    'network_asset.view',
                    'network_asset.nas.view',
                    'network_asset.account.view', 'network_asset.account.manage', 'network_asset.account.reveal',
                    'ipam.view', 'odn.view', 'odn.assign',
                    'location.view',
                    'inventory.view', 'inventory.create', 'inventory.update',
                    'inventory.stock.receive', 'inventory.stock.issue', 'inventory.stock.transfer',
                    'inventory.stock.adjust.request',
                    'inventory.request.view.all',
                    'inventory.request.prepare', 'inventory.request.issue',
                    'inventory.return.view.all', 'inventory.return.receive',
                    'spk.view', 'spk.create', 'spk.start', 'spk.submit',
                    'billing.view', 'billing.create', 'billing.send', 'billing.payment.record',
                    'ticket.view', 'ticket.create', 'ticket.comment.create',
                    'ticket.attachment.upload',
                    'evaluation.view', 'evaluation.create', 'evaluation.update',
                    'report.view',
                    'report.business.view', 'report.asset.view', 'report.sla.view',
                    'report.stock_card.view', 'report.dictionary.view',
                ],
            ],
            [
                'key' => 'customer',
                'name' => 'Pelanggan',
                'permissions' => [
                    'portal.access',

                    'dashboard.view',
                    'ticket.view', 'ticket.create', 'ticket.comment.create', 'ticket.attachment.upload',
                    'evaluation.customer.submit',
                    'portal.invoice.view', 'portal.invoice.pay',
                ],
            ],
        ];
    }

    /**
     * Creates every catalogue permission and each default template that does not exist yet, with its permissions. An
     * existing template is never touched, so an operator's edits survive a seeder run or a deploy. For fresh installs
     * and tests (the role-template migration and RolePermissionSeeder); a release that adds one permission uses release().
     */
    public static function install(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now],
            self::ALL,
        ));

        $ids = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');

        foreach (self::templates() as $template) {
            $taken = DB::table('role_templates')->where('key', $template['key'])->orWhere('name', $template['name'])->exists();

            if ($taken) {
                continue;
            }

            $templateId = DB::table('role_templates')->insertGetId([
                'public_id' => PublicId::new(),
                'key' => $template['key'],
                'name' => $template['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('role_template_has_permissions')->insert(array_map(
                fn (string $name): array => ['role_template_id' => $templateId, 'permission_id' => $ids[$name]],
                $template['permissions'],
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * For the migration of a release that adds a permission: creates it, adds it to those templates and grants it to
     * every company role copied from them. Nothing is removed and nothing is resynced: custom roles are the tenant's
     * own, and its administrator, who holds the permission through the Admin copy, adds it in Peran. Without the grant
     * to the copies nobody in an existing company could hold it, so nobody could place it (the hold-it-yourself guard).
     * The reach is by `template_key`, the copy's provenance, so a copy the company renamed or repurposed (an Admin copy
     * turned into "Operator") gains the permission too. That was accepted in the design (ADR-004): the alternative is a
     * guess per permission about which roles may have it. The company's administrator removes it in Peran where it does
     * not fit, and a deleted template's key is never reused (RoleTemplateService::keyTaken), so the reach never lands on
     * the copies of an unrelated template.
     * Add the permission to ALL and to templates() as well, so a company created later starts with it.
     *
     * @param  list<string>  $templateKeys
     */
    public static function release(string $permission, array $templateKeys): void
    {
        DB::transaction(function () use ($permission, $templateKeys): void {
            $now = now();

            DB::table('permissions')->insertOrIgnore(['name' => $permission, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            $permissionId = (int) DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');

            DB::table('role_template_has_permissions')->insertOrIgnoreUsing(
                ['role_template_id', 'permission_id'],
                DB::table('role_templates')->whereIn('key', $templateKeys)->select('id')->selectRaw('? as permission_id', [$permissionId]),
            );

            DB::table('role_has_permissions')->insertOrIgnoreUsing(
                ['role_id', 'permission_id'],
                DB::table('roles')->whereIn('template_key', $templateKeys)->select('id')->selectRaw('? as permission_id', [$permissionId]),
            );
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
