<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\RunsRoleMigrations;

/**
 * The audience and scope markers (app.web, app.technician, portal.access, evaluation.evaluable,
 * report.technician.team) replace role-name checks. The migration gives them to the global roles by their legacy names
 * before the cutover copies those roles into the companies, so nobody gains or loses access.
 */
class AccessPermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RunsRoleMigrations;

    private const ACCESS_PERMISSIONS = '2026_10_29_100200_add_access_permissions.php';

    private const MARKERS = ['app.web', 'app.technician', 'portal.access', 'evaluation.evaluable', 'report.technician.team'];

    public function test_access_permissions_follow_the_legacy_role_names(): void
    {
        $this->underLegacyRoles(function (): void {
            $migration = $this->migration(self::ACCESS_PERMISSIONS);
            $migration->down();

            $roles = [];

            foreach (['admin', 'manager', 'staff', 'technician', 'customer', 'noc', 'support-lead', 'Technician Lead'] as $name) {
                $roles[$name] = DB::table('roles')->insertGetId([
                    'public_id' => PublicId::new(), 'name' => $name, 'guard_name' => 'web', 'company_id' => null, 'template_key' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $kept = DB::table('permissions')->where('name', 'dashboard.view')->value('id');
            DB::table('role_has_permissions')->insert(array_map(fn (int $role): array => ['permission_id' => $kept, 'role_id' => $role], $roles));

            $migration->up();

            $expected = [
                'admin' => ['app.web', 'report.technician.team'],
                'manager' => ['app.web', 'report.technician.team'],
                'staff' => ['app.web', 'evaluation.evaluable'],
                'technician' => ['app.technician', 'evaluation.evaluable'],
                'customer' => ['portal.access'],
                'noc' => ['app.web', 'evaluation.evaluable'],
                // A role of the operator's own opens the web, as the old redirect let it in; it names no audience.
                'support-lead' => ['app.web'],
                'Technician Lead' => ['app.web'],
            ];

            foreach ($roles as $name => $roleId) {
                $held = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                    ->where('role_id', $roleId)->pluck('permissions.name')->all();

                $this->assertEqualsCanonicalizing(['dashboard.view', ...$expected[$name]], $held, "{$name} keeps what it had and gets the markers of its legacy name");
            }
        });
    }

    public function test_a_user_with_only_direct_permissions_gets_web_access(): void
    {
        $this->underLegacyRoles(function (): void {
            $migration = $this->migration(self::ACCESS_PERMISSIONS);
            $migration->down();

            $role = DB::table('roles')->insertGetId([
                'public_id' => PublicId::new(), 'name' => 'technician', 'guard_name' => 'web', 'company_id' => null, 'template_key' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $direct = User::factory()->create();
            $withRole = User::factory()->create();
            $nothing = User::factory()->create();
            $permission = DB::table('permissions')->where('name', 'customer.view')->value('id');

            foreach ([$direct, $withRole] as $user) {
                DB::table('model_has_permissions')->insert(['permission_id' => $permission, 'model_type' => User::class, 'model_id' => $user->id]);
            }

            DB::table('model_has_roles')->insert(['role_id' => $role, 'model_type' => User::class, 'model_id' => $withRole->id]);

            $migration->up();

            $this->assertTrue($this->holdsDirectly($direct, 'app.web'));
            $this->assertFalse($this->holdsDirectly($withRole, 'app.web'), 'a user with a role keeps what his roles give him');
            $this->assertFalse($this->holdsDirectly($nothing, 'app.web'));
        });
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->underLegacyRoles(function (): void {
            $migration = $this->migration(self::ACCESS_PERMISSIONS);
            $migration->down();
            $user = User::factory()->create();
            DB::table('model_has_permissions')->insert(['permission_id' => DB::table('permissions')->where('name', 'customer.view')->value('id'), 'model_type' => User::class, 'model_id' => $user->id]);

            $migration->up();
            $first = [DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count(), DB::table('permissions')->count()];
            $migration->up();

            $this->assertSame($first, [DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count(), DB::table('permissions')->count()]);
        });
    }

    public function test_down_removes_the_access_permissions(): void
    {
        $grants = fn (): int => DB::table('role_template_has_permissions')->count();
        $this->assertSame(5, DB::table('permissions')->whereIn('name', self::MARKERS)->count());
        $markerGrants = DB::table('role_template_has_permissions')->join('permissions', 'permissions.id', '=', 'role_template_has_permissions.permission_id')->whereIn('permissions.name', self::MARKERS)->count();
        $this->assertGreaterThan(0, $markerGrants, 'the default templates hold the markers');
        $before = $grants();

        $this->migration(self::ACCESS_PERMISSIONS)->down();

        $this->assertSame(0, DB::table('permissions')->whereIn('name', self::MARKERS)->count());
        $this->assertSame($before - $markerGrants, $grants(), 'the grants go with the permissions');
    }

    private function holdsDirectly(User $user, string $permission): bool
    {
        return DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', User::class)
            ->where('model_has_permissions.model_id', $user->id)
            ->where('permissions.name', $permission)
            ->exists();
    }
}
