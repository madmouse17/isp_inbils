<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Services\Core\CompanyRoleService;
use App\Services\Core\CompanyService;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles belong to companies (ADR-004), so a test names the company it grants a role in. TestCase uses this trait, so
 * every test has the four helpers. The permission catalogue and the role templates exist after `migrate` (the
 * migrations install them), so no test seeds them; a company gets its copies the first time a helper needs one.
 *
 * Each helper acts as the company (CompanyService::runAs) and drops the user's cached `roles` relation. What writes a role
 * or its permissions flushes the package's permission cache (the copy of the templates, companyRole()); assigning a role
 * to a user flushes nothing, as in production, so a test that warmed the cache to count queries keeps it warm.
 */
trait GrantsCompanyRoles
{
    /**
     * The company's copies of the platform templates, copied in once.
     *
     * @return array<string, Role> template key => role
     */
    protected function companyRoles(Company|int $company): array
    {
        // Reading is not writing: a company that already holds its copies is not copied again, so a permission cache the
        // test warmed stays warm (a write transaction flushes it).
        $copies = CompanyService::runAs($this->roleCompanyId($company), fn (): array => Role::query()->whereNotNull('template_key')->get()->keyBy('template_key')->all());

        if ($copies !== []) {
            return $copies;
        }

        $company = $company instanceof Company ? $company : Company::query()->findOrFail($company);
        $service = app(CompanyRoleService::class);
        $copies = $service->copyTemplates($company);

        // copyTemplates() leaves a company that already holds roles of its own alone (companyRole() ran first): give it the
        // copies whose names are free, so a test may build its custom roles and its templates in any order.
        if ($copies === [] && RoleTemplate::query()->exists()) {
            $copies = $service->transaction((int) $company->id, function () use ($company): array {
                $taken = Role::query()->pluck('name')->map(fn (string $name): string => mb_strtolower($name))->all();

                foreach (RoleTemplate::query()->with('permissions')->orderBy('id')->get() as $template) {
                    if (in_array(mb_strtolower($template->name), $taken, true)) {
                        continue;
                    }

                    $role = new Role(['name' => $template->name, 'guard_name' => 'web']);
                    $role->forceFill(['company_id' => $company->id, 'template_key' => $template->key])->save();
                    $role->permissions()->attach($template->permissions->modelKeys());
                }

                /** @var array<string, Role> $copies */
                $copies = Role::query()->whereNotNull('template_key')->get()->keyBy('template_key')->all();

                return $copies;
            });
        }

        return $copies;
    }

    /**
     * Gives $user a role in a company: a template key (`admin`, `manager`, `staff`, `technician`, `customer`, `noc`,
     * `warehouse`, `finance`), a role name of the company, or a role. The company defaults to his home company.
     */
    protected function grantRole(User $user, string|Role $role, Company|int|null $company = null): User
    {
        $companyId = $this->roleCompanyId($company ?? $user->company_id ?? throw new InvalidArgumentException('The user has no home company: name the company.'));

        if (is_string($role)) {
            $role = $this->companyRoles($companyId)[$role]
                ?? CompanyService::runAs($companyId, fn (): ?Role => Role::query()->where('name', $role)->first())
                ?? throw new InvalidArgumentException("Company {$companyId} has no role [{$role}].");
        }

        CompanyService::runAs($companyId, function () use ($user, $role): void {
            $user->unsetRelation('roles');
            $user->assignRole($role);
        });

        return $this->refreshRoles($user);
    }

    /**
     * A role of the company with exactly these permissions: the existing one of that name (its permissions are
     * replaced), or a new one. Permissions outside the catalogue are created.
     *
     * @param  list<string>  $permissions
     */
    protected function companyRole(Company|int $company, string $name, array $permissions): Role
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        return CompanyService::runAs($this->roleCompanyId($company), function () use ($name, $permissions): Role {
            $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role;
        });
    }

    /**
     * An ad hoc role `perms-<md5>` holding exactly these permissions, granted to $user in the company (his home company
     * by default). Roles add up: a second call stacks a second role.
     *
     * @param  list<string>  $permissions
     */
    protected function grantPermissions(User $user, array $permissions, Company|int|null $company = null): User
    {
        $companyId = $this->roleCompanyId($company ?? $user->company_id ?? throw new InvalidArgumentException('The user has no home company: name the company.'));
        $names = array_values(array_unique($permissions));
        sort($names);

        return $this->grantRole($user, $this->companyRole($companyId, 'perms-'.md5(implode('|', $names)), $names), $companyId);
    }

    private function roleCompanyId(Company|int $company): int
    {
        return $company instanceof Company ? (int) $company->id : $company;
    }

    /**
     * Assigning a role changes no permission with its roles, so the package's cache of those stays as it is (assignRole()
     * flushes it for a Permission only); the next check reads the roles of the user again.
     */
    private function refreshRoles(User $user): User
    {
        $user->unsetRelation('roles');

        return $user;
    }
}
