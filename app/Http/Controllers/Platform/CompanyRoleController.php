<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CompanyRoles\DestroyCompanyRoleRequest;
use App\Http\Requests\Platform\CompanyRoles\StoreCompanyRoleRequest;
use App\Http\Requests\Platform\CompanyRoles\UpdateCompanyRoleRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Services\Core\CompanyRoleService;
use App\Support\OptimisticLock;
use App\Support\Platform\TenantRoles;
use App\Support\RecordNotFound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * The operator's repair of one tenant's roles: add a role to a company (optionally from a template), edit it, or
 * delete it with its users moved. The writes go through CompanyRoleService, which holds the guards (separation of
 * duties, last administrator, stale edit) and writes the `platform` and `support` audit rows; the route group puts
 * them behind the platform step-up. `{company}` is the tenant's head; a role of another tenant answers 404.
 */
class CompanyRoleController extends Controller
{
    public function create(Request $request, Company $company): Response
    {
        $target = $request->query('target');
        $template = $request->query('template');

        $target = $target === null ? $company : (TenantRoles::company($company, $target) ?? RecordNotFound::abort());
        $template = $template === null ? null : (RoleTemplate::query()->with('permissions')->where('key', $template)->first() ?? RecordNotFound::abort());

        return Inertia::render('Platform/Companies/Roles/Create', [
            'tenant' => $this->companyProps($company),
            'company' => $this->companyProps($target),
            'permissions' => $this->permissions(),
            'template' => $template === null ? null : [
                'key' => $template->key,
                'name' => $template->name,
                'permissions' => $template->permissions->pluck('name')->sort()->values()->all(),
            ],
        ]);
    }

    public function store(StoreCompanyRoleRequest $request, Company $company, CompanyRoleService $roles): RedirectResponse
    {
        /** @var Company $target the rule on company_id proved it belongs to this tenant */
        $target = $request->targetCompany();

        $roles->create($this->admin($request), $target, $request->validated('name'), $request->permissionNames());

        return $this->shown($company, 'Peran dibuat.');
    }

    public function edit(Company $company, string $role): Response
    {
        $role = TenantRoles::role($company, $role);

        return Inertia::render('Platform/Companies/Roles/Edit', [
            'tenant' => $this->companyProps($company),
            'company' => $this->companyProps(Company::query()->findOrFail($role->company_id)),
            'role' => JsonResource::make([
                'id' => $role->public_id,
                'name' => $role->name,
                'template_key' => $role->template_key,
                'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
                'users_count' => $role->users()->count(),
                'version' => OptimisticLock::version($role),
            ]),
            'permissions' => $this->permissions(),
            'moveTargets' => Role::withoutCompany()
                ->where('company_id', $role->company_id)
                ->where('guard_name', 'web')
                ->whereKeyNot($role->id)
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (Role $other): array => ['id' => $other->public_id, 'name' => $other->name])
                ->all(),
        ]);
    }

    public function update(UpdateCompanyRoleRequest $request, Company $company, CompanyRoleService $roles): RedirectResponse
    {
        $roles->update(
            $this->admin($request),
            $request->role(),
            $request->validated('name'),
            $request->permissionNames(),
            $request->validated(OptimisticLock::FIELD),
        );

        return $this->shown($company, 'Peran diperbarui.');
    }

    public function destroy(DestroyCompanyRoleRequest $request, Company $company, CompanyRoleService $roles): RedirectResponse
    {
        $roles->delete($this->admin($request), $request->role(), $request->moveTo());

        return $this->shown($company, 'Peran dihapus.');
    }

    /** @return array{id: string, name: string, code: string} */
    private function companyProps(Company $company): array
    {
        return ['id' => $company->public_id, 'name' => $company->name, 'code' => $company->code];
    }

    private function permissions(): AnonymousResourceCollection
    {
        return PermissionResource::collection(Permission::query()->where('guard_name', 'web')->orderBy('name')->get());
    }

    private function shown(Company $company, string $message): RedirectResponse
    {
        return redirect()->route('platform.companies.show', $company)->with('success', $message);
    }

    private function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }
}
