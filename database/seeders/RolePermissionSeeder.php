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
            'company.manage',
            'system.setting',
            'organization.view',
            'organization.manage',
            'employee.view',
            'employee.manage',
            'vehicle.view',
            'vehicle.manage',

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

            'service.view',
            'service.create',
            'service.update',
            'service.delete',
            'service.manage',

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

            'spk.view',
            'spk.create',
            'spk.update',
            'spk.delete',
            'spk.assign',
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
            'evaluation.manage',

            'report.view',
            'report.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);
        $manager = Role::firstOrCreate(['name' => 'manager'], ['guard_name' => 'web']);
        $staff = Role::firstOrCreate(['name' => 'staff'], ['guard_name' => 'web']);
        $technician = Role::firstOrCreate(['name' => 'technician'], ['guard_name' => 'web']);
        $customer = Role::firstOrCreate(['name' => 'customer'], ['guard_name' => 'web']);

        $admin->givePermissionTo($permissions);

        $manager->givePermissionTo([
            'customer.view', 'customer.create', 'customer.update',
            'customer.address.manage',
            'customer.subscription.view', 'customer.subscription.manage',
            'customer.subscription.activate', 'customer.subscription.suspend',
            'customer.subscription.reactivate', 'customer.subscription.terminate',
            'customer.export', 'customer.manage',

            'service.view', 'service.create', 'service.update', 'service.manage',

            'network_asset.view', 'network_asset.create', 'network_asset.update',
            'network_asset.install', 'network_asset.remove',
            'network_asset.maintenance', 'network_asset.repair', 'network_asset.retire',
            'network_asset.export', 'network_asset.manage',

            'location.view', 'location.create', 'location.update',
            'location.move', 'location.manage',

            'inventory.view', 'inventory.create', 'inventory.update',
            'inventory.export', 'inventory.manage',

            'spk.view', 'spk.create', 'spk.update',
            'spk.assign', 'spk.approve', 'spk.reject', 'spk.cancel',
            'spk.export', 'spk.manage',

            'billing.view', 'billing.create', 'billing.update',
            'billing.send', 'billing.payment.record',
            'billing.cancel', 'billing.suspend', 'billing.reactivate',
            'billing.pdf.view', 'billing.export', 'billing.manage',

            'ticket.view', 'ticket.create', 'ticket.update',
            'ticket.assign', 'ticket.close', 'ticket.spawn_spk',
            'ticket.export', 'ticket.manage',

            'evaluation.view', 'evaluation.create', 'evaluation.update',

            'report.view',
        ]);

        $staff->givePermissionTo([
            'customer.view', 'customer.create', 'customer.update',
            'customer.address.manage',
            'customer.subscription.view',

            'service.view',

            'network_asset.view',

            'location.view',

            'inventory.view', 'inventory.create', 'inventory.update',

            'spk.view', 'spk.create', 'spk.start', 'spk.submit',

            'billing.view', 'billing.create', 'billing.send', 'billing.payment.record',

            'ticket.view', 'ticket.create', 'ticket.comment.create',
            'ticket.attachment.upload',

            'evaluation.view', 'evaluation.create', 'evaluation.update',

            'report.view',
        ]);

        $technician->givePermissionTo([
            'customer.view',

            'network_asset.view',

            'location.view',

            'inventory.view',

            'spk.view', 'spk.start', 'spk.submit',
            'spk.evidence.upload', 'spk.evidence.view',

            'ticket.view', 'ticket.create', 'ticket.start', 'ticket.resolve',
            'ticket.comment.create',

            'evaluation.view.own',
        ]);

        $customer->givePermissionTo([
            'ticket.view', 'ticket.create', 'ticket.comment.create',
            'evaluation.customer.submit',
        ]);
    }
}
