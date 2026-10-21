<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Services\Platform\PlatformAuditService;
use App\Support\Notifications\StaffRecipients;
use App\Support\OptimisticLock;
use App\Support\PermissionCatalogue;
use App\Support\RecordNotFound;
use App\Support\SodConflicts;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every write of a company's roles (ADR-004): the copy of the platform templates into a new company, a tenant
 * administrator's create, rename, edit and delete, an operator's repair, and the roles a user holds in a company.
 *
 * One write is one transaction that first locks the company row, so two writers of the same company queue up, and
 * then acts as that company (CompanyService::runAs): the role model answers for that company only. The guards:
 *
 * - G1 hold it yourself: a tenant actor grants only guarded permissions he holds (PermissionCatalogue::UNGUARDED
 *   are markers and exempt). G2 edit scope: he changes only roles and users whose guarded permissions he covers.
 * - G3 separation of duties on a role's permission set, G4 on the roles a user holds together; a set holding
 *   roles.manage is exempt, and so is a tenant actor holding it (a platform admin is never exempt from G4).
 * - G5 a write may not take away the last administrator: an active member holding roles.manage and app.web. A
 *   company that already has none can still be repaired.
 * - G6 the role copied from the `customer` template is never deleted, and Pengguna never touches roles holding
 *   portal.access. G7 deleting a role with users moves them to another role first. G8 a stale edit is refused.
 * - G9 no role holds portal.access together with app.web: customers are outside parties, so the role of their
 *   accounts never opens the back office. Nobody is exempt, not even a set that holds roles.manage.
 *
 * A tenant actor audits in the `security` log, as himself, or as the support operator during a support session
 * (CauserResolver returns that override before an explicit causer); a platform actor in `platform` and, for the tenant to
 * read, `support`.
 */
class CompanyRoleService
{
    /** The template whose copy CustomerService gives every new customer account. */
    public const CUSTOMER_TEMPLATE = 'customer';

    public const LAST_ADMINISTRATOR = 'Perusahaan harus tetap punya minimal satu admin aktif yang memegang roles.manage.';

    public function __construct(private readonly PlatformAuditService $platformAudit) {}

    /**
     * Copies every platform template into the company. Only a company without roles gets them, so calling it twice
     * (the wizard and the seeder) copies once; after the copy the company owns its roles and nothing resyncs them.
     *
     * @return array<string, Role> template key => the company's role of that template
     */
    public function copyTemplates(Company $company): array
    {
        return $this->transaction((int) $company->id, function () use ($company): array {
            if (! Role::query()->exists()) {
                foreach (RoleTemplate::query()->with('permissions')->orderBy('id')->get() as $template) {
                    $role = $this->newRole((int) $company->id, $template->name, $template->key);
                    $role->permissions()->attach($template->permissions->modelKeys());
                }
            }

            /** @var array<string, Role> $copies */
            $copies = Role::query()->whereNotNull('template_key')->get()->keyBy('template_key')->all();

            return $copies;
        });
    }

    /** @param  list<string>  $permissions */
    public function create(User|PlatformAdmin $actor, Company $company, string $name, array $permissions): Role
    {
        $permissions = $this->names($permissions);
        $this->assertActsIn($actor, (int) $company->id);

        return $this->transaction((int) $company->id, function () use ($actor, $company, $name, $permissions): Role {
            $this->assertNameFree($name);
            $this->assertHeld($actor, $permissions, 'permissions');
            $this->assertNoSodConflict($permissions);
            self::assertNoPortalWithWebAccess($permissions);

            $role = $this->newRole((int) $company->id, $name);
            $this->setPermissions($role, $permissions);

            $this->audit($actor, 'role.created', ['name' => $name, 'permissions' => $permissions], $role);

            return $role;
        });
    }

    /**
     * @param  list<string>  $permissions
     * @param  string  $expectedUpdatedAt  the version of the form (OptimisticLock::version)
     */
    public function update(User|PlatformAdmin $actor, Role $role, string $name, array $permissions, string $expectedUpdatedAt): Role
    {
        $permissions = $this->names($permissions);
        $companyId = (int) $role->company_id;
        $this->assertActsIn($actor, $companyId);

        return $this->guardingAdministrator($companyId, function () use ($actor, $role, $name, $permissions, $expectedUpdatedAt): Role {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            OptimisticLock::assert($locked, $expectedUpdatedAt);

            $before = $this->permissionNames($locked);
            $added = array_values(array_diff($permissions, $before));
            $removed = array_values(array_diff($before, $permissions));

            $this->assertCovers($actor, $before);
            $this->assertNameFree($name, $locked->id);
            $this->assertHeld($actor, $added, 'permissions');
            $this->assertNoSodConflict($permissions);
            self::assertNoPortalWithWebAccess($permissions);

            $nameBefore = $locked->name;

            if ($nameBefore !== $name || $added !== [] || $removed !== []) {
                $locked->name = $name;
                $this->setPermissions($locked, $permissions);
                // The pivot rows do not move updated_at, and the next form is stale only if the version does.
                $locked->touch();

                $this->audit($actor, 'role.updated', [
                    'name_before' => $nameBefore,
                    'name_after' => $name,
                    'permissions_added' => $added,
                    'permissions_removed' => $removed,
                ], $locked);
            }

            return $locked;
        });
    }

    /**
     * Deletes the role. Users who still hold it move to $moveTo first (they keep what they have and get it), and the
     * stacking of their other roles with it must stay clean.
     */
    public function delete(User|PlatformAdmin $actor, Role $role, ?Role $moveTo): void
    {
        $companyId = (int) $role->company_id;
        $this->assertActsIn($actor, $companyId);

        $this->guardingAdministrator($companyId, function () use ($actor, $role, $moveTo): void {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            $permissions = $this->permissionNames($locked);

            $this->assertCovers($actor, $permissions);

            if ($locked->template_key === self::CUSTOMER_TEMPLATE) {
                throw ValidationException::withMessages(['role' => 'Peran ini dipakai untuk akun pelanggan baru.']);
            }

            $holders = $locked->users()->get();
            $target = null;

            if ($holders->isNotEmpty()) {
                $target = $this->moveTarget($actor, $locked, $moveTo);

                foreach ($holders as $holder) {
                    $this->moveHolder($actor, $holder, $locked, $target);
                }
            }

            $this->audit($actor, 'role.deleted', [
                'name' => $locked->name,
                'permissions' => $permissions,
                'moved_users' => $holders->count(),
                'moved_to' => $target?->name,
            ], $locked);

            $locked->delete();
        });
    }

    /**
     * Sets the roles $target holds in the current company to $roleNames. Roles holding portal.access are neither added
     * nor removed (CustomerService owns them), so their names in the input are ignored. Only what changes is written,
     * so the security trail records exactly that.
     *
     * @param  list<string>  $roleNames
     */
    public function syncUserRoles(User $actor, User $target, array $roleNames): void
    {
        $companyId = CompanyService::currentId() ?? abort(403);
        // The roles are the company's: a user who is no member of it has none to give, and does not exist for the actor.
        RecordNotFound::abortUnless($this->isMember($target, $companyId));

        $this->guardingAdministrator($companyId, function () use ($actor, $target, $roleNames): void {
            $current = $this->rolesOf($target);
            $wanted = $this->rolesNamed($roleNames)->reject(fn (Role $role): bool => $this->isPortal($role));

            $add = $wanted->reject(fn (Role $role): bool => $current->contains('id', $role->id))->values();
            $remove = $current
                ->reject(fn (Role $role): bool => $this->isPortal($role) || $wanted->contains('id', $role->id))
                ->values();

            $this->assertCovers($actor, $this->permissionsOf($current));
            $this->assertHeld($actor, $this->permissionsOf($add), 'roles');
            $this->assertNoStackedConflict(
                $actor,
                $current->reject(fn (Role $role): bool => $remove->contains('id', $role->id))->merge($add),
                'roles',
            );

            $target->unsetRelation('roles');

            if ($remove->isNotEmpty()) {
                $target->removeRole($remove->all());
            }

            if ($add->isNotEmpty()) {
                $target->assignRole($add->all());
            }
        });
    }

    /**
     * Gives $user in $to the roles that carry the template of each role he holds in $from, as far as he holds what they
     * grant. A company owns its roles: it may have trimmed its Staf copy or repurposed its Admin copy, and the copy in
     * $to still carries the template's full set. Copying by template alone would hand him there what $from took away
     * from him, so a role whose guarded permissions he does not all hold in $from (judged there, through his roles and
     * direct permissions) is not given: it is returned as `by_hand`, for a person to give in Pengguna. A role without
     * a template (a custom one) has no counterpart, so a member who holds only custom roles gets none. Idempotent: what
     * he already holds in $to is kept, and still named in `granted`.
     *
     * @return array{granted: list<string>, by_hand: list<string>} the roles of $to he holds now, and the matching ones left out
     */
    public function mirrorTemplateRoles(User $user, Company $from, Company $to): array
    {
        $keys = Role::withoutCompany()
            ->where('company_id', $from->id)
            ->whereIn('id', $this->heldRoleIds($user))
            ->whereNotNull('template_key')
            ->pluck('template_key')
            ->all();

        if ($keys === []) {
            return ['granted' => [], 'by_hand' => []];
        }

        return $this->transaction((int) $to->id, function () use ($user, $from, $keys): array {
            $user->unsetRelation('roles');
            $holds = CompanyService::runAs((int) $from->id, fn (): array => $user->getAllPermissions()->pluck('name')->all());
            $user->unsetRelation('roles');

            $matching = Role::query()->whereIn('template_key', $keys)->with('permissions:id,name')->orderBy('name')->get();
            $held = $this->heldRoleIds($user)->pluck('role_id')->map(fn ($id): int => (int) $id)->all();
            [$granted, $byHand] = $matching->partition(fn (Role $role): bool => in_array($role->id, $held, true)
                || array_diff(PermissionCatalogue::guarded($role->permissions->pluck('name')->all()), $holds) === []);
            $missing = $granted->reject(fn (Role $role): bool => in_array($role->id, $held, true));

            if ($missing->isNotEmpty()) {
                $user->assignRole($missing->all());
            }

            return ['granted' => $granted->pluck('name')->values()->all(), 'by_hand' => $byHand->pluck('name')->values()->all()];
        });
    }

    /**
     * G2: every guarded permission of $permissions is held by $actor in the current company, or in $companyId when given.
     * The company in effect is read the way the package answers for it; another company is read from what the actor
     * carries for every company (User::permissionNamesIn()), with no switch of company and no reload, so judging a
     * page of users in each of their companies costs no query per row.
     *
     * @param  list<string>  $permissions
     */
    public function covers(User $actor, array $permissions, ?int $companyId = null): bool
    {
        return $this->missing($actor, $permissions, $companyId) === [];
    }

    /**
     * G5 for callers that changed the company's roles or members in their own transaction (CompanyMembershipService):
     * after that change some active member other than $exceptUserId must still hold roles.manage
     * and app.web. Callers that only want "no regression" use guardingAdministrator() instead.
     *
     * @throws ValidationException
     */
    public function assertAdministratorRemains(int $companyId, ?int $exceptUserId = null, string $key = 'roles'): void
    {
        $remaining = $this->administrators($companyId)->when($exceptUserId !== null, fn (Builder $query) => $query->whereKeyNot($exceptUserId));

        if (! $remaining->exists()) {
            throw ValidationException::withMessages([$key => self::LAST_ADMINISTRATOR]);
        }
    }

    /** Is this user one of the company's administrators today: active, a member, holding roles.manage and app.web. */
    public function isAdministrator(User $user, int $companyId): bool
    {
        return $this->administrators($companyId)->whereKey($user->getKey())->exists();
    }

    /** Is the user a member of the company: it is his home, or he was granted membership. */
    public function isMember(User $user, int $companyId): bool
    {
        return (int) $user->company_id === $companyId
            || DB::table('company_user')->where('user_id', $user->getKey())->where('company_id', $companyId)->exists();
    }

    /**
     * One write of the company: a transaction that holds the company row, acting as that company. The row lock comes
     * before anything else is written, so two writers never wait on each other's half-done work (a deadlock), and
     * every guard reads a state nobody else is changing. Nests: the outermost call is the one that commits.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public function transaction(int $companyId, Closure $work): mixed
    {
        try {
            return DB::transaction(function () use ($companyId, $work): mixed {
                Company::query()->lockForUpdate()->findOrFail($companyId);

                return CompanyService::runAs($companyId, $work);
            });
        } finally {
            // The package caches every permission with its roles. Something read inside the transaction may have
            // cached rows that never commit, so the cache is dropped after the outcome is known, not before.
            $forget = static fn () => app(PermissionRegistrar::class)->forgetCachedPermissions();
            $forget();

            // Nested in a caller's transaction (the setup wizard) nothing is committed yet, and a request that rebuilds
            // the cache before the outer commit would keep the old roles until the next flush: drop it once more then.
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($forget);
            }
        }
    }

    /**
     * G5: a transaction in which the company that had an administrator before still has one after. A company that had
     * none is not blocked, so a broken company can be repaired through the same paths. The refusal answers on `roles`.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     *
     * @throws ValidationException
     */
    public function guardingAdministrator(int $companyId, Closure $work): mixed
    {
        return $this->transaction($companyId, function () use ($companyId, $work): mixed {
            $had = $this->administrators($companyId)->exists();
            $result = $work();

            if ($had) {
                $this->assertAdministratorRemains($companyId);
            }

            return $result;
        });
    }

    /**
     * G5 for a change that takes a user out of the roles of every company he belongs to at once: deleting him, or
     * deactivating his login. In each company where he is an administrator today another active administrator must
     * remain. The company rows are locked in id order as the first statements of the transaction, so every read after
     * them sees what the transactions that held those locks before committed: two administrators who delete or
     * deactivate each other cannot both pass on a snapshot that predates the other's commit. The companies are read
     * before the transaction starts for the same reason, so call this outside a transaction that has read already.
     * The refusal answers on $key.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work  runs after the check, in the same transaction
     * @return TReturn
     *
     * @throws ValidationException
     */
    public function guardingAdministratorsOf(User $user, string $key, Closure $work): mixed
    {
        $companyIds = app(CompanyMembershipService::class)->companyIdsOf($user)->sort()->values();

        return DB::transaction(function () use ($user, $key, $work, $companyIds): mixed {
            Company::query()->whereIn('id', $companyIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($companyIds as $companyId) {
                if ($this->isAdministrator($user, $companyId)) {
                    $this->assertAdministratorRemains($companyId, $user->getKey(), $key);
                }
            }

            return $work();
        });
    }

    /**
     * The active members of the company who hold roles.manage and app.web there, each permission asked on its own so
     * that one holder may have them through different roles, or directly.
     *
     * @return Builder<User>
     */
    private function administrators(int $companyId): Builder
    {
        return StaffRecipients::members($companyId)
            ->where(fn (Builder $holders) => $holders->withPermissionIn(PermissionCatalogue::ADMINISTRATION, $companyId))
            ->where(fn (Builder $holders) => $holders->withPermissionIn(PermissionCatalogue::WEB, $companyId));
    }

    private function newRole(int $companyId, string $name, ?string $templateKey = null): Role
    {
        $role = new Role(['name' => $name, 'guard_name' => 'web']);
        $role->forceFill(['company_id' => $companyId, 'template_key' => $templateKey])->save();

        return $role;
    }

    /** @param  list<string>  $names */
    private function setPermissions(Role $role, array $names): void
    {
        $ids = Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->pluck('id')->all();

        if (count($ids) !== count($names)) {
            throw ValidationException::withMessages(['permissions' => 'Hak akses tidak dikenal.']);
        }

        $role->permissions()->sync($ids);
        $role->unsetRelation('permissions');
    }

    /**
     * @param  array<mixed>  $permissions
     * @return list<string> the distinct permission names, sorted
     */
    private function names(array $permissions): array
    {
        $names = array_values(array_unique(array_filter($permissions, 'is_string')));
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function permissionNames(Role $role): array
    {
        return $role->loadMissing('permissions')->permissions->pluck('name')->sort()->values()->all();
    }

    /**
     * @param  Collection<int, Role>  $roles  loaded with their permissions
     * @return list<string>
     */
    private function permissionsOf(Collection $roles): array
    {
        return $roles->flatMap(fn (Role $role) => $role->permissions->pluck('name'))->unique()->values()->all();
    }

    /** The role of a customer account: it holds portal.access. Its permissions must be loaded. */
    public function isPortal(Role $role): bool
    {
        return $role->permissions->contains('name', PermissionCatalogue::PORTAL);
    }

    private function heldRoleIds(User $user): QueryBuilder
    {
        return DB::table('model_has_roles')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->select('role_id');
    }

    /** @return Collection<int, Role> the roles the user holds in the current company, with their permissions */
    private function rolesOf(User $user): Collection
    {
        return Role::query()->whereIn('id', $this->heldRoleIds($user))->with('permissions:id,name')->get();
    }

    /**
     * @param  array<mixed>  $names
     * @return Collection<int, Role> the current company's roles of those names, with their permissions
     */
    private function rolesNamed(array $names): Collection
    {
        $names = array_values(array_unique(array_filter($names, 'is_string')));
        $roles = Role::query()->where('guard_name', 'web')->whereIn('name', $names)->with('permissions:id,name')->get();

        if ($roles->count() < count($names)) {
            throw ValidationException::withMessages(['roles' => 'Peran tidak ditemukan di perusahaan ini.']);
        }

        return $roles;
    }

    /** A tenant actor writes only into the company he acts in: any other one does not exist for him. */
    private function assertActsIn(User|PlatformAdmin $actor, int $companyId): void
    {
        RecordNotFound::abortUnless($actor instanceof PlatformAdmin || CompanyService::idFor($actor) === $companyId);
    }

    private function assertNameFree(string $name, ?int $exceptRoleId = null): void
    {
        $taken = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $name)
            ->when($exceptRoleId !== null, fn (Builder $query) => $query->whereKeyNot($exceptRoleId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Nama peran sudah dipakai di perusahaan ini.']);
        }
    }

    /**
     * G1: a tenant actor grants only the guarded permissions he holds himself. An operator holds no tenant
     * permission and repairs any role, so the guard is the tenant's.
     *
     * @param  list<string>  $permissions
     */
    private function assertHeld(User|PlatformAdmin $actor, array $permissions, string $key): void
    {
        if (! $actor instanceof User) {
            return;
        }

        $missing = $this->missing($actor, $permissions);

        if ($missing !== []) {
            throw ValidationException::withMessages([$key => 'Anda tidak dapat memberi hak akses yang tidak Anda miliki: '.implode(', ', $missing).'.']);
        }
    }

    /**
     * G2: a tenant actor edits or deletes only a role, or changes only a user, whose guarded permissions he covers.
     *
     * @param  list<string>  $permissions  what the role or the user holds now
     */
    private function assertCovers(User|PlatformAdmin $actor, array $permissions): void
    {
        if ($actor instanceof User && ! $this->covers($actor, $permissions)) {
            throw new AuthorizationException('Anda tidak memegang semua hak akses yang dimuat peran ini.');
        }
    }

    /**
     * G3: the permissions of one role. A set that holds roles.manage is the administrator's break-glass role.
     *
     * @param  list<string>  $permissions
     */
    private function assertNoSodConflict(array $permissions): void
    {
        if (in_array(PermissionCatalogue::ADMINISTRATION, $permissions, true)) {
            return;
        }

        if (($violations = SodConflicts::violations($permissions)) !== []) {
            throw ValidationException::withMessages(['permissions' => $violations]);
        }
    }

    /**
     * G9: no role holds portal.access together with app.web. Applies to a company's role, a platform template and an
     * operator's repair alike, and to nobody is it waived.
     *
     * @param  list<string>  $permissions
     *
     * @throws ValidationException
     */
    public static function assertNoPortalWithWebAccess(array $permissions): void
    {
        if (in_array(PermissionCatalogue::PORTAL, $permissions, true) && in_array(PermissionCatalogue::WEB, $permissions, true)) {
            throw ValidationException::withMessages(['permissions' => 'Peran akun pelanggan ('.PermissionCatalogue::PORTAL.') tidak boleh memuat akses web ('.PermissionCatalogue::WEB.').']);
        }
    }

    /**
     * G4: the permissions of every role a user holds in the company, merged. The tenant actor who holds roles.manage
     * is exempt; an operator is not.
     *
     * @param  Collection<int, Role>  $roles  the whole set the user ends up with, loaded with their permissions
     */
    private function assertNoStackedConflict(User|PlatformAdmin $actor, Collection $roles, string $key): void
    {
        if ($actor instanceof User && $actor->can(PermissionCatalogue::ADMINISTRATION)) {
            return;
        }

        if (($violations = SodConflicts::violations($this->permissionsOf($roles))) !== []) {
            throw ValidationException::withMessages([$key => $violations]);
        }
    }

    /**
     * G7: the role that takes over the users of a deleted one: another role of the company, one the actor may grant,
     * and not the role of customer accounts (a staff member does not become a customer by a deletion).
     *
     * @throws ValidationException
     */
    private function moveTarget(User|PlatformAdmin $actor, Role $deleted, ?Role $moveTo): Role
    {
        if ($moveTo === null) {
            throw ValidationException::withMessages(['move_to' => 'Pilih peran tujuan untuk memindahkan pengguna.']);
        }

        $target = Role::query()->with('permissions:id,name')->whereKeyNot($deleted->id)->find($moveTo->id);

        if ($target === null) {
            throw ValidationException::withMessages(['move_to' => 'Pilih peran lain sebagai tujuan.']);
        }

        if ($this->isPortal($target)) {
            throw ValidationException::withMessages(['move_to' => 'Peran akun pelanggan tidak bisa menjadi tujuan.']);
        }

        $this->assertHeld($actor, $this->permissionNames($target), 'move_to');

        return $target;
    }

    private function moveHolder(User|PlatformAdmin $actor, User $holder, Role $deleted, Role $target): void
    {
        $rest = $this->rolesOf($holder)->reject(fn (Role $role): bool => $role->is($deleted));

        $this->assertNoStackedConflict($actor, $rest->merge([$target]), 'roles');

        if (! $rest->contains('id', $target->id)) {
            $holder->unsetRelation('roles');
            $holder->assignRole($target);
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string> the guarded ones among them that $actor does not hold in the current company, or in $companyId when given
     */
    private function missing(User $actor, array $permissions, ?int $companyId = null): array
    {
        $held = $companyId === null ? $actor->getAllPermissions()->pluck('name')->all() : $actor->permissionNamesIn($companyId);

        return array_values(array_diff(PermissionCatalogue::guarded($permissions), $held));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(User|PlatformAdmin $actor, string $action, array $properties, Role $role): void
    {
        if ($actor instanceof PlatformAdmin) {
            $this->platformAudit->platform($action, $properties, $role, $actor);
            $this->platformAudit->support($action, $properties, $role, $actor);

            return;
        }

        AuditService::log('security', $action, $properties, $role, causer: $actor);
    }
}
