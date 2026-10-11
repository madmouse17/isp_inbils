<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Core admin
            'users.manage',
            'roles.manage',
            'roles.export',
            'company.manage',
            // Reset another user's two-factor authentication (admin only).
            'user.two_factor.reset',
            // Switch the active company: needs a membership of the target company on top (CompanyPolicy).
            'company.switch',
            'system.setting',
            'dashboard.view',
            // Developer gallery is intentionally admin-only (fail-closed):
            // seeded only onto the admin role, never onto non-admin roles.
            'developer.components.view',
            'organization.view',
            'organization.manage',
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

            'organization.view',
            'organization.manage',
            'organization.export',

            'document.export',
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
            // Router provisioning (MikroTik): manage = admin only, reveal is audited.
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

            'evaluation.view',
            'evaluation.create',
            'evaluation.update',
            'evaluation.delete',
            'evaluation.view.own',
            'evaluation.customer.submit',
            'evaluation.export',
            'evaluation.manage',

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
            'report.manage',
            'report.audit.view',
            'report.export',
            // Consolidated Business metrics of a Head company over its subsidiaries. Admin only.
            'report.consolidated.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);
        $manager = Role::firstOrCreate(['name' => 'manager'], ['guard_name' => 'web']);
        $staff = Role::firstOrCreate(['name' => 'staff'], ['guard_name' => 'web']);
        $technician = Role::firstOrCreate(['name' => 'technician'], ['guard_name' => 'web']);
        $customer = Role::firstOrCreate(['name' => 'customer'], ['guard_name' => 'web']);

        // Portal permissions belong to customers only: staff pay nothing through the portal.
        $admin->syncPermissions(array_values(array_diff($permissions, ['portal.invoice.view', 'portal.invoice.pay'])));

        $manager->syncPermissions([
            'customer.view', 'customer.create', 'customer.update',
            'customer.address.manage',
            'customer.subscription.view', 'customer.subscription.manage',
            'customer.subscription.activate', 'customer.subscription.suspend',
            'customer.subscription.reactivate', 'customer.subscription.terminate',
            'customer.export', 'customer.manage',
            'customer.sales.assign', 'customer.sales.correct',

            'service.view', 'service.create', 'service.update', 'service.manage',
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
            'ticket.export', 'ticket.manage',

            'evaluation.view', 'evaluation.create', 'evaluation.update',

            'report.view', 'report.technician',
            'report.business.view', 'report.asset.view', 'report.sla.view',
            'report.stock_card.view', 'report.dictionary.view', 'report.sales.view',
            'report.export',
            'company.switch',
        ]);

        // Every demo role lands on /admin/dashboard after login (the auth
        // controller redirects intended() there), so the dashboard gate must
        // be open to all of them or post-login navigation 403s.
        $staff->syncPermissions([
            'dashboard.view',
            'customer.view', 'customer.create', 'customer.update',
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
        ]);

        $technician->syncPermissions([
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
            'ticket.comment.create',
            'evaluation.view.own',
            'report.technician',
        ]);

        $customer->syncPermissions([
            'dashboard.view',
            'ticket.view', 'ticket.create', 'ticket.comment.create',
            'evaluation.customer.submit',
            'portal.invoice.view', 'portal.invoice.pay',
        ]);
    }
}
