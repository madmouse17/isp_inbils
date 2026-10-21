<?php

use App\Models\User;
use App\Support\PublicId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    private const BEFORE = 'role_cutover_before';

    private const AFTER = 'role_cutover_after';

    private const MAP = 'role_cutover_map';

    /** The five seeded roles: legacy name => the name their copies take (OD1). The legacy name becomes the template_key. */
    private const SEEDED = ['admin' => 'Admin', 'manager' => 'Manajer', 'staff' => 'Staf', 'technician' => 'Teknisi', 'customer' => 'Pelanggan'];

    /** Templates an existing company gets without any user (OD2); down() ignores such a role. */
    private const TOP_UP = ['noc', 'warehouse', 'finance'];

    /** Who belongs to which company: the home company and every membership. */
    private const MEMBERS = 'select id as user_id, company_id from users where company_id is not null union select user_id, company_id from company_user';

    /**
     * Roles stop being global (ADR-004). Every global role is copied into every company with the same permissions, and
     * every assignment is remapped to the copy in each company the user belongs to (his home company and every
     * membership), so each user keeps exactly the permissions he has in each company. The migration proves it: the
     * permissions of every (user, company) pair are snapshotted before and after, and any difference rolls everything
     * back. Assignments of a user who belongs to no company cannot be mapped and are dropped (he acts in no company,
     * so they gave him nothing); they are counted in the log. Existing companies also get the templates they lack (OD2),
     * without users.
     *
     * One transaction, and no DDL inside it: the snapshot tables are TEMPORARY and created before it. The roles are
     * read and written with DB::table(): the role model hides a role without a company. MySQL only (temporary tables).
     */
    public function up(): void
    {
        $this->createTemporaryTables();

        try {
            $summary = DB::transaction(fn (): array => $this->cutOver());
        } finally {
            $this->dropTemporaryTables();
        }

        Log::info('roles.cutover', $summary);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The immediate rollback window: it restores one global role per name when no company changed its roles since the
     * cutover, and refuses otherwise (the pre-deploy backup is then the way back). A top-up role without users is ignored.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $roles = DB::table('roles')->whereNotNull('company_id')->orderBy('id')->get(['id', 'name', 'guard_name', 'company_id', 'template_key']);

            if ($roles->isEmpty()) {
                return;
            }

            $holders = DB::table('model_has_roles')->selectRaw('role_id, count(*) as users')->groupBy('role_id')->pluck('users', 'role_id');
            $topUp = fn (object $role): bool => in_array($role->template_key, self::TOP_UP, true);

            if ($roles->contains(fn (object $role): bool => $topUp($role) && (int) ($holders[$role->id] ?? 0) > 0)) {
                $this->refuseToRestore();
            }

            $kept = $roles->reject($topUp);
            $permissions = $this->permissionSets($kept);
            $companyCount = DB::table('companies')->count();
            $groups = $kept->groupBy(fn (object $role): string => mb_strtolower($role->name));

            foreach ($groups as $group) {
                $uniform = $group->count() === $companyCount
                    && $group->pluck('template_key')->unique()->count() === 1
                    && $group->map(fn (object $role): string => $permissions[$role->id] ?? '')->unique()->count() === 1;

                if (! $uniform) {
                    $this->refuseToRestore();
                }
            }

            $names = $groups->map(function (Collection $group): string {
                $first = $group->first();
                $key = $first->template_key === null ? null : mb_strtolower($first->template_key);

                return $key !== null && isset(self::SEEDED[$key]) ? $key : $first->name;
            });

            if ($names->map(fn (string $name): string => mb_strtolower($name))->duplicates()->isNotEmpty() || ! $this->holdersAreUniform()) {
                $this->refuseToRestore();
            }

            $this->restoreGlobalRoles($groups, $names);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array{companies: int, roles_copied: int, templates_added: int, assignments: int, unmapped_assignments: int} */
    private function cutOver(): array
    {
        $global = DB::table('roles')->whereNull('company_id')->orderBy('id')->get(['id', 'name', 'guard_name']);
        $companyIds = DB::table('companies')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id);

        $foreignHolder = DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->whereNull('r.company_id')
            ->where('mhr.model_type', '<>', User::class)
            ->exists();

        if ($foreignHolder) {
            throw new RuntimeException('A global role is held by something that is not a user: the cutover cannot map it to a company.');
        }

        $unmapped = $this->unmappedAssignments();

        $this->snapshot(self::BEFORE, 'r.company_id is null');

        $copies = $this->copyGlobalRoles($global, $companyIds);
        $assignments = $this->remapAssignments();
        DB::table('roles')->whereNull('company_id')->delete();
        $added = $this->addMissingTemplates($companyIds);

        $this->snapshot(self::AFTER, 'r.company_id = m.company_id');
        $this->assertUnchanged();

        return [
            'companies' => $companyIds->count(),
            'roles_copied' => $copies,
            'templates_added' => $added,
            'assignments' => $assignments,
            'unmapped_assignments' => $unmapped,
        ];
    }

    /**
     * The permissions of every (user, company) pair: his direct ones, and those of the roles he holds. Before, a role
     * is global ($role = `r.company_id is null`) and applies in every company of the user; after, it counts in its own
     * company only (`r.company_id = m.company_id`).
     */
    private function snapshot(string $table, string $role): void
    {
        DB::insert(
            "insert into {$table} (user_id, company_id, permission_id) "
            .'select m.user_id, m.company_id, mhp.permission_id from ('.self::MEMBERS.') m '
            .'join model_has_permissions mhp on mhp.model_id = m.user_id and mhp.model_type = ? '
            .'union '
            .'select m.user_id, m.company_id, rhp.permission_id from ('.self::MEMBERS.') m '
            .'join model_has_roles mhr on mhr.model_id = m.user_id and mhr.model_type = ? '
            ."join roles r on r.id = mhr.role_id and {$role} "
            .'join role_has_permissions rhp on rhp.role_id = r.id',
            [User::class, User::class],
        );
    }

    /**
     * Compares the two snapshots both ways. Each statement reads each temporary table once, which MySQL requires.
     *
     * @throws RuntimeException
     */
    protected function assertUnchanged(): void
    {
        $changed = collect(DB::select($this->pairsMissingFrom(self::AFTER, self::BEFORE), [], false))
            ->merge(DB::select($this->pairsMissingFrom(self::BEFORE, self::AFTER), [], false))
            ->map(fn (object $pair): string => $pair->user_id.':'.$pair->company_id)
            ->unique();

        if ($changed->isNotEmpty()) {
            throw new RuntimeException("Role cutover would change the permissions of {$changed->count()} user-company pairs; nothing was changed.");
        }
    }

    /** The (user, company) pairs that hold a permission in $from that $against lacks. */
    private function pairsMissingFrom(string $against, string $from): string
    {
        return "select distinct f.user_id, f.company_id from {$from} f where not exists "
            ."(select 1 from {$against} a where a.user_id = f.user_id and a.company_id = f.company_id and a.permission_id = f.permission_id)";
    }

    /** Assignments of users who belong to no company: they have nowhere to go. */
    private function unmappedAssignments(): int
    {
        return (int) DB::scalar(
            'select count(*) from model_has_roles mhr join roles r on r.id = mhr.role_id and r.company_id is null '
            .'where not exists (select 1 from users u where u.id = mhr.model_id and u.company_id is not null) '
            .'and not exists (select 1 from company_user cu where cu.user_id = mhr.model_id)',
            [],
            false,
        );
    }

    /**
     * One copy of every global role in every company, active or not, with the permissions of the original. The five
     * seeded roles take the template names and the legacy name as their key, unless another global role already has
     * that name; every other role keeps its name and has no key.
     *
     * @param  Collection<int, object>  $global
     * @param  Collection<int, int>  $companyIds
     */
    private function copyGlobalRoles(Collection $global, Collection $companyIds): int
    {
        $taken = $global->mapWithKeys(fn (object $role): array => [mb_strtolower($role->name) => $role->id]);
        $plan = $global->mapWithKeys(function (object $role) use ($taken): array {
            $legacy = mb_strtolower($role->name);
            $template = self::SEEDED[$legacy] ?? null;
            $free = $template !== null && ($taken[mb_strtolower($template)] ?? $role->id) === $role->id;

            return [$role->id => ['name' => $free ? $template : $role->name, 'key' => $template !== null ? $legacy : null]];
        });
        $now = now();
        $copies = 0;

        foreach ($companyIds as $companyId) {
            $map = [];

            foreach ($global as $role) {
                $copyId = DB::table('roles')->insertGetId([
                    'public_id' => PublicId::new(),
                    'name' => $plan[$role->id]['name'],
                    'guard_name' => $role->guard_name,
                    'company_id' => $companyId,
                    'template_key' => $plan[$role->id]['key'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('role_has_permissions')->insertUsing(
                    ['permission_id', 'role_id'],
                    DB::table('role_has_permissions')->where('role_id', $role->id)->selectRaw('permission_id, ?', [$copyId]),
                );

                $map[] = ['global_role_id' => $role->id, 'company_id' => $companyId, 'copy_role_id' => $copyId];
                $copies++;
            }

            if ($map !== []) {
                DB::table(self::MAP)->insert($map);
            }
        }

        return $copies;
    }

    /** Each assignment of a global role becomes the copy in every company of the user. @return int the rows written */
    private function remapAssignments(): int
    {
        return DB::affectingStatement(
            'insert into model_has_roles (role_id, model_type, model_id) '
            .'select cm.copy_role_id, mhr.model_type, mhr.model_id from model_has_roles mhr '
            .'join ('.self::MEMBERS.') m on m.user_id = mhr.model_id '
            .'join '.self::MAP.' cm on cm.global_role_id = mhr.role_id and cm.company_id = m.company_id '
            .'where mhr.model_type = ?',
            [User::class],
        );
    }

    /**
     * The templates a company lacks (no role with its key, or with its name), created from the template, with no user.
     *
     * @param  Collection<int, int>  $companyIds
     */
    private function addMissingTemplates(Collection $companyIds): int
    {
        $templates = DB::table('role_templates')->orderBy('id')->get(['id', 'key', 'name']);
        $now = now();
        $added = 0;

        foreach ($companyIds as $companyId) {
            $roles = DB::table('roles')->where('company_id', $companyId)->get(['name', 'template_key']);
            $keys = $roles->pluck('template_key')->all();
            $names = $roles->map(fn (object $role): string => mb_strtolower($role->name))->all();

            foreach ($templates as $template) {
                if (in_array($template->key, $keys, true) || in_array(mb_strtolower($template->name), $names, true)) {
                    continue;
                }

                $roleId = DB::table('roles')->insertGetId([
                    'public_id' => PublicId::new(),
                    'name' => $template->name,
                    'guard_name' => 'web',
                    'company_id' => $companyId,
                    'template_key' => $template->key,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('role_has_permissions')->insertUsing(
                    ['permission_id', 'role_id'],
                    DB::table('role_template_has_permissions')->where('role_template_id', $template->id)->selectRaw('permission_id, ?', [$roleId]),
                );

                $added++;
            }
        }

        return $added;
    }

    /** The snapshot tables and the map of global role and company to its copy; they live for this connection only. */
    protected function createTemporaryTables(): void
    {
        $this->dropTemporaryTables();

        foreach ([self::BEFORE, self::AFTER] as $table) {
            DB::statement("create temporary table {$table} (user_id bigint unsigned not null, company_id bigint unsigned not null, permission_id bigint unsigned not null, primary key (user_id, company_id, permission_id))");
        }

        DB::statement('create temporary table '.self::MAP.' (global_role_id bigint unsigned not null, company_id bigint unsigned not null, copy_role_id bigint unsigned not null, primary key (global_role_id, company_id))');
    }

    protected function dropTemporaryTables(): void
    {
        foreach ([self::BEFORE, self::AFTER, self::MAP] as $table) {
            DB::statement("drop temporary table if exists {$table}");
        }
    }

    /**
     * @param  Collection<int, object>  $roles
     * @return array<int, string> role id => its permission ids, sorted and joined
     */
    private function permissionSets(Collection $roles): array
    {
        $sets = [];

        foreach (DB::table('role_has_permissions')->whereIn('role_id', $roles->pluck('id'))->orderBy('permission_id')->get(['role_id', 'permission_id']) as $row) {
            $sets[(int) $row->role_id][] = (int) $row->permission_id;
        }

        return array_map(fn (array $ids): string => implode(',', $ids), $sets);
    }

    /** Does every user hold the same role names in every company he belongs to? Otherwise one global role would widen or narrow him. */
    private function holdersAreUniform(): bool
    {
        $companies = [];

        foreach (DB::select(self::MEMBERS) as $member) {
            $companies[(int) $member->user_id][(int) $member->company_id] = [];
        }

        $held = DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->whereNotNull('r.company_id')
            ->get(['mhr.model_id as user_id', 'r.company_id', 'r.name']);

        foreach ($held as $row) {
            $companies[(int) $row->user_id][(int) $row->company_id][] = mb_strtolower($row->name);
        }

        foreach ($companies as $perCompany) {
            $sets = array_unique(array_map(function (array $names): string {
                sort($names);

                return implode('|', $names);
            }, $perCompany));

            if (count($sets) > 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * One global role per name, with the permissions of its copies, held by every user who holds any copy of it.
     *
     * @param  Collection<string, Collection<int, object>>  $groups  the company roles of each name
     * @param  Collection<string, string>  $names  the name each group is restored under
     */
    private function restoreGlobalRoles(Collection $groups, Collection $names): void
    {
        $now = now();
        $globalOf = [];

        foreach ($groups as $group => $roles) {
            $first = $roles->first();
            $globalId = DB::table('roles')->insertGetId([
                'public_id' => PublicId::new(),
                'name' => $names[$group],
                'guard_name' => $first->guard_name,
                'company_id' => null,
                'template_key' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('role_has_permissions')->insertUsing(
                ['permission_id', 'role_id'],
                DB::table('role_has_permissions')->where('role_id', $first->id)->selectRaw('permission_id, ?', [$globalId]),
            );

            foreach ($roles as $role) {
                $globalOf[(int) $role->id] = $globalId;
            }
        }

        $assignments = [];

        foreach (DB::table('model_has_roles')->whereIn('role_id', array_keys($globalOf))->get() as $held) {
            $globalId = $globalOf[(int) $held->role_id];
            $assignments["{$globalId}:{$held->model_type}:{$held->model_id}"] = ['role_id' => $globalId, 'model_type' => $held->model_type, 'model_id' => $held->model_id];
        }

        foreach (array_chunk(array_values($assignments), 500) as $chunk) {
            DB::table('model_has_roles')->insertOrIgnore($chunk);
        }

        DB::table('roles')->whereNotNull('company_id')->delete();
    }

    /** @throws RuntimeException */
    private function refuseToRestore(): never
    {
        throw new RuntimeException('Roles changed per company since the cutover; restore the pre-deploy backup instead.');
    }
};
