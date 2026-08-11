<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RoleController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Role::class);

        $roles = $this->filteredQuery($request)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Roles/Index', [
            'roles' => RoleResource::collection($roles),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => $request->user()?->can('roles.manage') ?? false,
                'export' => $request->user()?->can('roles.export') ?? false,
            ],
        ]);
    }

    public function create(): InertiaResponse
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

        return redirect()->route('admin.roles.index')->with('success', 'Role created.');
    }

    public function edit(Role $role): InertiaResponse
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

        return redirect()->route('admin.roles.index')->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $role->delete();

        return back()->with('success', 'Role deleted.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('roles.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('name', 'asc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'name' => 'Name',
            'permissions_count' => 'Permissions',
            'users_count' => 'Users',
            'guard_name' => 'Guard',
        ];

        $map = static fn (Role $role): array => [
            'name' => $role->name,
            'permissions_count' => $role->permissions_count ?? 0,
            'users_count' => $role->users_count ?? 0,
            'guard_name' => $role->guard_name,
        ];

        return $format === 'pdf'
            ? $export->streamPdf('Roles', $columns, $map, "roles-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "roles-export-{$stamp}.csv");
    }

    /**
     * @return Builder<Role>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Role::query()
            ->with('permissions')
            ->withCount(['permissions', 'users'])
            ->when($request->input('search'), function (Builder $query, string $value): void {
                $term = trim($value);

                if ($term === '') {
                    return;
                }

                $query->where('name', 'like', '%'.$term.'%');
            });

        return $this->applySort($query, $request, 'name');
    }
}
