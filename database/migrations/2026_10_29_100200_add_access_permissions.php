<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = ['app.web', 'app.technician', 'portal.access', 'evaluation.evaluable', 'report.technician.team'];

    /**
     * The audience and scope markers that replace role-name checks (ADR-004). They go to the GLOBAL roles by their
     * legacy names, before the cutover copies those roles into the companies, so each user keeps exactly what the
     * names gave him: web access for every role but the two app roles, the technician and customer audiences, the
     * evaluable employees (EmployeeEvaluation::EVALUABLE_ROLES, as it was) and the manager's team scope on the technician
     * report. A user who holds no role but has direct permissions got through the old redirect, so he gets web
     * access directly. Roles are touched with DB::table(): the role model hides a role without a company.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            DB::table('permissions')->insertOrIgnore(array_map(
                fn (string $name): array => ['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now],
                self::PERMISSIONS,
            ));

            $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('id', 'name');
            $grants = [];

            foreach (DB::table('roles')->whereNull('company_id')->where('guard_name', 'web')->get(['id', 'name']) as $role) {
                foreach ($this->grantedTo(mb_strtolower($role->name)) as $permission) {
                    $grants[] = ['permission_id' => $ids[$permission], 'role_id' => $role->id];
                }
            }

            if ($grants !== []) {
                DB::table('role_has_permissions')->insertOrIgnore($grants);
            }

            DB::table('model_has_permissions')->insertOrIgnoreUsing(
                ['permission_id', 'model_type', 'model_id'],
                DB::table('model_has_permissions as mhp')
                    ->where('mhp.model_type', User::class)
                    ->whereNotExists(fn ($roles) => $roles->from('model_has_roles as mhr')
                        ->whereColumn('mhr.model_id', 'mhp.model_id')
                        ->where('mhr.model_type', User::class))
                    ->distinct()
                    ->selectRaw('? as permission_id, mhp.model_type, mhp.model_id', [$ids['app.web']]),
            );
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The pivots cascade, so the grants go with the permissions. */
    public function down(): void
    {
        DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function grantedTo(string $legacyName): array
    {
        return array_values(array_filter([
            ! in_array($legacyName, ['technician', 'customer'], true) ? 'app.web' : null,
            $legacyName === 'technician' ? 'app.technician' : null,
            $legacyName === 'customer' ? 'portal.access' : null,
            in_array($legacyName, ['technician', 'staff', 'noc'], true) ? 'evaluation.evaluable' : null,
            in_array($legacyName, ['admin', 'manager'], true) ? 'report.technician.team' : null,
        ]));
    }
};
