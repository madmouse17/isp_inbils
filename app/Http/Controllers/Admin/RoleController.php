<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()
            ->with('permissions')
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Roles/Index', [
            'roles' => RoleResource::collection($roles),
            'can' => [
                'create' => $request->user()?->can('roles.manage') ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('Admin/Roles/Create', [
            'permissions' => PermissionResource::collection(Permission::query()->orderBy('name')->get()),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        Gate::authorize('store', Role::class);

        $data = $request->validated();
        $permissions = $data['permissions'] ?? [];

        $role = Role::query()->create([
            'name' => $data['name'],
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($permissions);

        return back()->with('success', 'Role created.');
    }

    public function edit(Role $role): Response
    {
        Gate::authorize('edit', $role);

        return Inertia::render('Admin/Roles/Edit', [
            'role' => new RoleResource($role->load('permissions')->loadCount('users')),
            'permissions' => PermissionResource::collection(Permission::query()->orderBy('name')->get()),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        Gate::authorize('update', $role);

        $data = $request->validated();
        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $role->delete();

        return back()->with('success', 'Role deleted.');
    }
}
