<?php

use App\Support\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    /** The templates whose people bring a finished ticket back to work (OD1): the manager, who closes it, and NOC, who answers the customer. */
    private const TEMPLATES = ['manager', 'noc'];

    /**
     * `ticket.reopen` has been in the catalogue (and in the Admin template) since the first release but nothing used it.
     * Staff reopen with a reason now, so the two templates get it, and with them every company role copied from them
     * (PermissionCatalogue::release()). The company's own roles are left alone: its administrator adds the permission in Peran.
     */
    public function up(): void
    {
        PermissionCatalogue::release('ticket.reopen', self::TEMPLATES);
    }

    /**
     * Takes the grants back from the two templates and from the roles copied from them. The permission itself stays: it
     * existed before this release and the Admin template holds it.
     */
    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'ticket.reopen')->where('guard_name', 'web')->value('id');

        if ($permissionId !== null) {
            DB::table('role_template_has_permissions')
                ->where('permission_id', $permissionId)
                ->whereIn('role_template_id', DB::table('role_templates')->whereIn('key', self::TEMPLATES)->select('id'))
                ->delete();

            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', DB::table('roles')->whereIn('template_key', self::TEMPLATES)->select('id'))
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
