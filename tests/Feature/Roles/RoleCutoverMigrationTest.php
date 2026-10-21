<?php

namespace Tests\Feature\Roles;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use App\Support\PermissionCatalogue;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\RunsRoleMigrations;

/**
 * The cutover from global roles to roles per company (ADR-004): the proof that no user gains or loses a permission in
 * any company. The test builds the state before the cutover (global roles, users with roles and direct permissions in
 * their home company and in memberships), runs the access-permission migration and the cutover, and compares what
 * every user holds per company with what the legacy rules gave him.
 *
 * A global role needs `roles.company_id` to be nullable, which is DDL and commits the transaction RefreshDatabase
 * opened. So each scenario starts a transaction of its own after the DDL and rolls it back before the column becomes
 * required again: nothing the scenario writes is kept.
 */
class RoleCutoverMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RunsRoleMigrations;

    private const ACCESS_PERMISSIONS = '2026_10_29_100200_add_access_permissions.php';

    private const CUTOVER = '2026_10_29_100400_copy_global_roles_into_companies.php';

    /** The five permissions the access migration adds on purpose, so the comparison leaves them out. */
    private const MARKERS = ['app.web', 'app.technician', 'portal.access', 'evaluation.evaluable', 'report.technician.team'];

    /** @var array<string, int> global role ids of the scenario by legacy name */
    private array $roleIds = [];

    public function test_every_user_keeps_the_same_permissions_in_every_company(): void
    {
        $this->underLegacyRoles(function (): void {
            $this->buildWorld();
            $expected = $this->legacyPermissions();
            $this->assertCount(15, $expected, 'The legacy snapshot should hold a set of permissions for each of the 15 user and company pairs of the world.');

            $this->cutOver();

            $pairs = DB::select('select id as user_id, company_id from users where company_id is not null union select user_id, company_id from company_user');
            $this->assertNotEmpty($pairs);

            foreach ($pairs as $pair) {
                $this->assertSame(
                    $expected["{$pair->user_id}:{$pair->company_id}"] ?? [],
                    $this->permissionsIn((int) $pair->user_id, (int) $pair->company_id),
                    'User '.User::query()->find($pair->user_id)?->name.' in company '.$pair->company_id,
                );
            }
        });
    }

    public function test_each_company_gets_a_copy_of_every_global_role_with_its_permissions(): void
    {
        $this->underLegacyRoles(function (): void {
            $this->buildWorld();
            $legacy = $this->legacyRolePermissions();

            $this->cutOver();

            $this->assertSame(0, DB::table('roles')->whereNull('company_id')->count());

            foreach (DB::table('companies')->pluck('id') as $companyId) {
                foreach ($legacy as $name => $permissions) {
                    $copy = DB::table('roles')->where('company_id', $companyId)->where(fn ($query) => $query->where('name', $name)->orWhere('name', $this->copyName($name)))->first();

                    $this->assertNotNull($copy, "Company {$companyId} should hold a copy of {$name}.");
                    $this->assertEqualsCanonicalizing(
                        [...$permissions, ...$this->markersOf($name)],
                        DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('role_id', $copy->id)->pluck('permissions.name')->all(),
                        "Copy of {$name} in company {$companyId}",
                    );
                }
            }
        });
    }

    public function test_assignments_follow_the_home_company_and_every_membership(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();

            $this->cutOver();

            [$head, $sub, $other] = [$world['head']->id, $world['sub']->id, $world['other']->id];

            $this->assertSame([[$head, 'staff']], $this->heldBy($world['homeOnly']));
            $this->assertSame([[$head, 'admin'], [$sub, 'admin']], $this->heldBy($world['homeAndMember']));
            $this->assertSame([[$head, 'manager'], [$other, 'manager']], $this->heldBy($world['memberOnly']));
            $this->assertEqualsCanonicalizing([[$head, 'manager'], [$head, 'staff']], $this->heldBy($world['stacked']));
            $this->assertSame([], $this->heldBy($world['directOnly']), 'a user with direct permissions only has no role to copy');
        });
    }

    public function test_copies_of_the_seeded_roles_get_the_template_name_and_key(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();

            $this->cutOver();

            foreach ([$world['head'], $world['sub'], $world['other']] as $company) {
                $named = DB::table('roles')->where('company_id', $company->id)->whereNotNull('template_key')->pluck('name', 'template_key')->all();

                foreach (['admin' => 'Admin', 'manager' => 'Manajer', 'staff' => 'Staf', 'technician' => 'Teknisi', 'customer' => 'Pelanggan'] as $key => $name) {
                    $this->assertSame($name, $named[$key] ?? null, "{$key} in company {$company->id}");
                }
            }
        });
    }

    public function test_a_custom_global_role_keeps_its_name_without_template_key(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();

            $this->cutOver();

            foreach ([$world['head'], $world['sub'], $world['other']] as $company) {
                foreach (['noc', 'support-lead'] as $name) {
                    $copy = DB::table('roles')->where('company_id', $company->id)->where('name', $name)->first();

                    $this->assertNotNull($copy, "{$name} in company {$company->id}");
                    $this->assertNull($copy->template_key);
                }
            }
        });
    }

    public function test_missing_templates_are_added_without_users(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();

            $this->cutOver();

            $templates = collect(PermissionCatalogue::templates())->keyBy('key');

            foreach ([$world['head'], $world['sub'], $world['other']] as $company) {
                foreach (['noc', 'warehouse', 'finance'] as $key) {
                    $role = DB::table('roles')->where('company_id', $company->id)->where('template_key', $key)->first();

                    $this->assertNotNull($role, "{$key} in company {$company->id}");
                    $this->assertSame($templates[$key]['name'], $role->name);
                    $this->assertSame(0, DB::table('model_has_roles')->where('role_id', $role->id)->count());
                    $this->assertEqualsCanonicalizing(
                        $templates[$key]['permissions'],
                        DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('role_id', $role->id)->pluck('permissions.name')->all(),
                    );
                }
            }
        });
    }

    public function test_assignments_of_a_user_without_company_are_dropped_and_logged(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();
            Log::spy();

            $this->cutOver();

            $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $world['companyless']->id)->count());
            Log::shouldHaveReceived('info')->once()->with('roles.cutover', Mockery::on(
                fn (array $context): bool => $context['unmapped_assignments'] === 1 && $context['companies'] === 3,
            ));
        });
    }

    public function test_the_self_check_refuses_a_changed_pair(): void
    {
        $migration = $this->migration(self::CUTOVER);
        $this->callProtected($migration, 'createTemporaryTables');

        try {
            DB::table('role_cutover_before')->insert([
                ['user_id' => 7, 'company_id' => 1, 'permission_id' => 10],
                ['user_id' => 7, 'company_id' => 1, 'permission_id' => 11],
                ['user_id' => 8, 'company_id' => 1, 'permission_id' => 10],
                ['user_id' => 9, 'company_id' => 2, 'permission_id' => 10],
            ]);
            DB::table('role_cutover_after')->insert([
                ['user_id' => 7, 'company_id' => 1, 'permission_id' => 10],
                ['user_id' => 8, 'company_id' => 1, 'permission_id' => 10],
                ['user_id' => 8, 'company_id' => 1, 'permission_id' => 12],
                ['user_id' => 9, 'company_id' => 2, 'permission_id' => 10],
            ]);

            // User 7 lost a permission and user 8 gained one: two pairs, each counted once.
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Role cutover would change the permissions of 2 user-company pairs; nothing was changed.');

            $this->callProtected($migration, 'assertUnchanged');
        } finally {
            $this->callProtected($migration, 'dropTemporaryTables');
        }
    }

    public function test_the_self_check_passes_identical_snapshots(): void
    {
        $migration = $this->migration(self::CUTOVER);
        $this->callProtected($migration, 'createTemporaryTables');

        try {
            foreach (['role_cutover_before', 'role_cutover_after'] as $table) {
                DB::table($table)->insert([
                    ['user_id' => 7, 'company_id' => 1, 'permission_id' => 10],
                    ['user_id' => 7, 'company_id' => 2, 'permission_id' => 10],
                ]);
            }

            $this->callProtected($migration, 'assertUnchanged');
            $this->addToAssertionCount(1);
        } finally {
            $this->callProtected($migration, 'dropTemporaryTables');
        }
    }

    public function test_down_restores_global_roles_when_no_company_diverged(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();
            // The assignments of a user who belongs to no company cannot be mapped: the cutover drops them on purpose.
            $roleNamesBefore = $this->roleNamesOfUsers();
            unset($roleNamesBefore[$world['companyless']->id]);

            $this->cutOver();
            $this->migration(self::CUTOVER)->down();
            $this->migration(self::ACCESS_PERMISSIONS)->down();

            $this->assertSame(0, DB::table('roles')->whereNotNull('company_id')->count(), 'every copy is gone');
            $this->assertEqualsCanonicalizing(
                array_keys($this->legacyRolePermissions()),
                DB::table('roles')->whereNull('company_id')->pluck('name')->all(),
                'one global role per name, the legacy name of the five restored',
            );

            foreach ($this->legacyRolePermissions() as $name => $permissions) {
                $this->assertEqualsCanonicalizing(
                    $permissions,
                    DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                        ->where('roles.name', $name)->whereNull('roles.company_id')->pluck('permissions.name')->all(),
                    "Permissions of the restored {$name}",
                );
            }

            $this->assertSame($roleNamesBefore, $this->roleNamesOfUsers(), 'every user holds the global roles he held before');
            $this->assertSame([], DB::table('model_has_roles')->where('model_id', $world['companyless']->id)->pluck('role_id')->all());
        });
    }

    public function test_down_refuses_after_a_company_renamed_a_role(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();
            $this->cutOver();

            DB::table('roles')->where('company_id', $world['sub']->id)->where('template_key', 'admin')->update(['name' => 'Pemilik']);

            $this->assertDownRefuses();
        });
    }

    public function test_down_refuses_when_two_companies_differ_in_a_role_permission_set(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();
            $this->cutOver();

            $role = DB::table('roles')->where('company_id', $world['sub']->id)->where('template_key', 'technician')->first();
            DB::table('role_has_permissions')->where('role_id', $role->id)->limit(1)->delete();

            $this->assertDownRefuses();
        });
    }

    public function test_down_refuses_when_a_user_holds_other_roles_in_one_of_his_companies(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();
            $this->cutOver();

            $copy = DB::table('roles')->where('company_id', $world['sub']->id)->where('template_key', 'admin')->first();
            DB::table('model_has_roles')->where('role_id', $copy->id)->where('model_id', $world['homeAndMember']->id)->delete();

            $this->assertDownRefuses();
        });
    }

    public function test_down_refuses_when_a_top_up_role_has_users(): void
    {
        $this->underLegacyRoles(function (): void {
            $world = $this->buildWorld();
            $this->cutOver();

            $finance = DB::table('roles')->where('company_id', $world['head']->id)->where('template_key', 'finance')->first();
            DB::table('model_has_roles')->insert(['role_id' => $finance->id, 'model_type' => User::class, 'model_id' => $world['homeOnly']->id]);

            $this->assertDownRefuses();
        });
    }

    /** The access permissions, then the cutover itself, as `migrate` runs them. */
    private function cutOver(): void
    {
        $this->migration(self::ACCESS_PERMISSIONS)->up();
        $this->migration(self::CUTOVER)->up();
    }

    private function assertDownRefuses(): void
    {
        try {
            $this->migration(self::CUTOVER)->down();
            $this->fail('down() should refuse once the companies diverged.');
        } catch (RuntimeException $e) {
            $this->assertSame('Roles changed per company since the cutover; restore the pre-deploy backup instead.', $e->getMessage());
        }

        $this->assertSame(0, DB::table('roles')->whereNull('company_id')->count(), 'a refusal changes nothing');
    }

    /**
     * Three companies (a head, its subsidiary, an unrelated tenant), the legacy global roles, and users who hold them
     * in every way the cutover must map: one role, stacked roles, a home company only, a home company and a
     * membership, a membership without a home, direct permissions only, a role plus a direct permission, and a user
     * who belongs to no company.
     *
     * @return array<string, mixed>
     */
    private function buildWorld(): array
    {
        $head = Company::factory()->create(['is_active' => true]);
        $sub = Company::factory()->create(['is_active' => true]);
        $sub->forceFill(['parent_company_id' => $head->id])->save();
        $other = Company::factory()->create(['is_active' => true]);

        foreach ($this->legacyRolePermissions() as $name => $permissions) {
            $roleId = DB::table('roles')->insertGetId([
                'public_id' => PublicId::new(), 'name' => $name, 'guard_name' => 'web', 'company_id' => null, 'template_key' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->roleIds[$name] = $roleId;
            DB::table('role_has_permissions')->insert(DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $permissions)->pluck('id')
                ->map(fn ($id): array => ['permission_id' => $id, 'role_id' => $roleId])->all());
        }

        $memberships = app(CompanyMembershipService::class);
        $make = function (string $name, ?Company $home, array $roles, array $members = [], array $direct = []) use ($memberships): User {
            $user = User::factory()->create(['name' => $name, 'company_id' => $home?->id]);

            foreach ($members as $company) {
                $memberships->grant($user, $company);
            }

            foreach ($roles as $role) {
                DB::table('model_has_roles')->insert(['role_id' => $this->roleIds[$role], 'model_type' => User::class, 'model_id' => $user->id]);
            }

            foreach (DB::table('permissions')->whereIn('name', $direct)->pluck('id') as $permissionId) {
                DB::table('model_has_permissions')->insert(['permission_id' => $permissionId, 'model_type' => User::class, 'model_id' => $user->id]);
            }

            return $user;
        };

        return [
            'head' => $head, 'sub' => $sub, 'other' => $other,
            'admin' => $make('Admin home', $head, ['admin']),
            'manager' => $make('Manager home', $head, ['manager']),
            'staff' => $make('Staff home', $head, ['staff']),
            'technician' => $make('Technician home', $head, ['technician']),
            'customer' => $make('Customer home', $head, ['customer']),
            'noc' => $make('Noc other', $other, ['noc']),
            'lead' => $make('Lead sub', $sub, ['support-lead']),
            'stacked' => $make('Stacked home', $head, ['manager', 'staff']),
            'homeOnly' => $make('Home only', $head, ['staff']),
            'homeAndMember' => $make('Home and member', $head, ['admin'], [$sub]),
            'memberOnly' => $make('Member only', null, ['manager'], [$head, $other]),
            'directOnly' => $make('Direct only', $other, [], [], ['customer.view', 'ticket.view']),
            'roleAndDirect' => $make('Role and direct', $sub, ['technician'], [], ['billing.view']),
            'companyless' => $make('Company-less', null, ['staff']),
        ];
    }

    /**
     * What each legacy role held before the cutover: the templates' sets without the five access permissions (the five
     * seeded roles), as the seeder gave them; admin was the whole catalogue but the customer portal; staff also held
     * customer.create. Two custom roles stand for what operators made.
     *
     * @return array<string, list<string>>
     */
    private function legacyRolePermissions(): array
    {
        $templates = collect(PermissionCatalogue::templates())->keyBy('key');
        $legacy = fn (string $key): array => array_values(array_diff($templates[$key]['permissions'], self::MARKERS));

        return [
            'admin' => array_values(array_diff(PermissionCatalogue::ALL, self::MARKERS, ['portal.invoice.view', 'portal.invoice.pay'])),
            'manager' => $legacy('manager'),
            'staff' => [...$legacy('staff'), 'customer.create'],
            'technician' => $legacy('technician'),
            'customer' => $legacy('customer'),
            'noc' => ['customer.view', 'ticket.view', 'ticket.assign'],
            'support-lead' => ['ticket.view', 'ticket.close'],
        ];
    }

    /** The markers the access migration gives a global role by its legacy name. @return list<string> */
    private function markersOf(string $role): array
    {
        return array_values(array_filter([
            ! in_array($role, ['technician', 'customer'], true) ? 'app.web' : null,
            $role === 'technician' ? 'app.technician' : null,
            $role === 'customer' ? 'portal.access' : null,
            in_array($role, ['technician', 'staff', 'noc'], true) ? 'evaluation.evaluable' : null,
            in_array($role, ['admin', 'manager'], true) ? 'report.technician.team' : null,
        ]));
    }

    private function copyName(string $legacyName): string
    {
        return ['admin' => 'Admin', 'manager' => 'Manajer', 'staff' => 'Staf', 'technician' => 'Teknisi', 'customer' => 'Pelanggan'][$legacyName] ?? $legacyName;
    }

    /**
     * What the legacy rules gave each (user, company) pair, read with SQL that knows nothing of the new model: the
     * direct permissions of the user plus those of every global role he holds, in each company he belongs to (his
     * home company and every membership). The five new permissions are left out.
     *
     * @return array<string, list<string>> "userId:companyId" => sorted permission names
     */
    private function legacyPermissions(): array
    {
        $rows = DB::select(
            'with m as (select id as user_id, company_id from users where company_id is not null union select user_id, company_id from company_user), '
            .'g as ('
            .'select m.user_id, m.company_id, rhp.permission_id from m '
            .'join model_has_roles mhr on mhr.model_id = m.user_id and mhr.model_type = ? '
            .'join role_has_permissions rhp on rhp.role_id = mhr.role_id '
            .'union '
            .'select m.user_id, m.company_id, mhp.permission_id from m '
            .'join model_has_permissions mhp on mhp.model_id = m.user_id and mhp.model_type = ?) '
            .'select g.user_id, g.company_id, p.name from g join permissions p on p.id = g.permission_id and p.name not in ('.implode(',', array_fill(0, count(self::MARKERS), '?')).')',
            [User::class, User::class, ...self::MARKERS],
        );

        $held = [];

        foreach ($rows as $row) {
            $held["{$row->user_id}:{$row->company_id}"][] = $row->name;
        }

        return array_map(function (array $names): array {
            sort($names);

            return $names;
        }, $held);
    }

    /** What a user holds in a company through the new model, the five access permissions left out. @return list<string> */
    private function permissionsIn(int $userId, int $companyId): array
    {
        $names = CompanyService::runAs($companyId, fn (): array => User::query()->findOrFail($userId)->getAllPermissions()->pluck('name')->all());
        $names = array_values(array_diff($names, self::MARKERS));
        sort($names);

        return $names;
    }

    /** @return list<array{0: int, 1: string}> (company id, template key or name) of the roles a user holds, in order */
    private function heldBy(User $user): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->orderBy('roles.company_id')->orderBy('roles.template_key')
            ->get(['roles.company_id', 'roles.template_key', 'roles.name'])
            ->map(fn (object $role): array => [(int) $role->company_id, $role->template_key ?? $role->name])
            ->all();
    }

    /** @return array<int, list<string>> the global role names each user holds */
    private function roleNamesOfUsers(): array
    {
        $names = [];

        foreach (DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->get(['model_has_roles.model_id', 'roles.name']) as $row) {
            $names[(int) $row->model_id][] = $row->name;
        }

        foreach ($names as &$list) {
            sort($list);
        }

        ksort($names);

        return $names;
    }

    private function callProtected(object $migration, string $method): mixed
    {
        return (new ReflectionMethod($migration, $method))->invoke($migration);
    }
}
