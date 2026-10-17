<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Roles\StoreRoleRequest;
use App\Http\Requests\Platform\Roles\UpdateRoleRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\PlatformAdmin;
use App\Services\Platform\PlatformRoleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The one place role definitions are written (ADR-003). Roles are global, so the user counts are across every
 * tenant; the writes sit behind the platform step-up (routes/platform/roles.php) and are audited by the service.
 */
class RoleController extends Controller
{
    use HasIndexQuery;

    public const SORTABLE = ['name', 'created_at'];

    public function index(Request $request): Response
    {
        // The `users` relation takes its model from the role's guard, and a platform request's default guard is
        // `platform`: a role that names `web` counts the tenant users.
        $roles = $this->applySort(
            (new Role(['guard_name' => 'web']))->newQuery()
                ->where('guard_name', 'web')
                ->with('permissions')
                ->withCount('users')
                ->when(trim((string) $request->input('search')), fn (Builder $query, string $term) => $query->where('name', 'like', '%'.$term.'%')),
            $request,
            'name',
        )->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Platform/Roles/Index', [
            'roles' => RoleResource::collection($roles),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'protectedRoles' => PlatformRoleService::PROTECTED,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Roles/Create', ['permissions' => $this->permissions()]);
    }

    public function store(StoreRoleRequest $request, PlatformRoleService $roles): RedirectResponse
    {
        $roles->create($this->admin($request), $request->validated('name'), $request->permissionNames());

        return redirect()->route('platform.roles.index')->with('success', 'Peran dibuat.');
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('Platform/Roles/Edit', [
            'role' => new RoleResource($role->load('permissions')->loadCount('users')),
            'permissions' => $this->permissions(),
            'protectedRoles' => PlatformRoleService::PROTECTED,
            'seededWarning' => PlatformRoleService::isProtected($role) ? PlatformRoleService::SEEDED_WARNING : null,
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role, PlatformRoleService $roles): RedirectResponse
    {
        $roles->update($this->admin($request), $role, $request->validated('name'), $request->permissionNames());

        return redirect()->route('platform.roles.index')->with('success', 'Peran diperbarui.');
    }

    public function destroy(Request $request, Role $role, PlatformRoleService $roles): RedirectResponse
    {
        $roles->delete($this->admin($request), $role);

        return redirect()->route('platform.roles.index')->with('success', 'Peran dihapus.');
    }

    private function permissions(): AnonymousResourceCollection
    {
        return PermissionResource::collection(Permission::query()->where('guard_name', 'web')->orderBy('name')->get());
    }

    private function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }
}
