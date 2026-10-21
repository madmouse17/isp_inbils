<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Roles\DestroyRoleRequest;
use App\Http\Requests\Admin\Roles\StoreRoleRequest;
use App\Http\Requests\Admin\Roles\UpdateRoleRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Services\Core\CompanyRoleService;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The roles of the company the user acts in. A role belongs to one company (ADR-004), so `{role}` resolves only
 * among them: another company's role answers 404 like a role that does not exist. The writes, with their guards and
 * their audit rows, are CompanyRoleService's.
 */
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
            'moveTargets' => Role::query()->orderBy('name')->get(['id', 'public_id', 'name'])
                ->map(fn (Role $role): array => ['id' => $role->public_id, 'name' => $role->name])
                ->all(),
            'templateNames' => RoleTemplate::query()->pluck('name', 'key'),
            'can' => [
                'create' => $request->user()?->can('create', Role::class) ?? false,
                'export' => $request->user()?->can('roles.export') ?? false,
            ],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('Admin/Roles/Create', [
            'permissions' => $this->permissions(),
            'templates' => RoleTemplate::query()->with('permissions:id,name')->orderBy('id')->get()
                ->map(fn (RoleTemplate $template): array => [
                    'key' => $template->key,
                    'name' => $template->name,
                    'permissions' => $template->permissions->pluck('name')->sort()->values()->all(),
                ])
                ->all(),
        ]);
    }

    public function store(StoreRoleRequest $request, CompanyRoleService $roles): RedirectResponse
    {
        $roles->create($request->user(), CompanyService::current() ?? abort(403), $request->validated('name'), $request->permissionNames());

        return redirect()->route('admin.roles.index')->with('success', 'Peran dibuat.');
    }

    public function show(Request $request, Role $role): InertiaResponse
    {
        Gate::authorize('view', $role);

        $resource = new RoleResource($role->load('permissions')->loadCount('users'));

        return Inertia::render('Admin/Roles/Show', [
            'role' => $resource,
            'permissions' => $this->permissions(),
            'can' => $resource->resolve($request)['can'],
        ]);
    }

    public function edit(Role $role): InertiaResponse
    {
        Gate::authorize('edit', $role);

        return Inertia::render('Admin/Roles/Edit', [
            'role' => new RoleResource($role->load('permissions')->loadCount('users')),
            'permissions' => $this->permissions(),
            'templateName' => $role->template_key === null ? null : RoleTemplate::query()->where('key', $role->template_key)->value('name'),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role, CompanyRoleService $roles): RedirectResponse
    {
        $roles->update(
            $request->user(),
            $role,
            $request->validated('name'),
            $request->permissionNames(),
            $request->validated('expected_updated_at'),
        );

        return redirect()->route('admin.roles.index')->with('success', 'Peran diperbarui.');
    }

    public function destroy(DestroyRoleRequest $request, Role $role, CompanyRoleService $roles): RedirectResponse
    {
        $roles->delete($request->user(), $role, $request->moveTo());

        return redirect()->route('admin.roles.index')->with('success', 'Peran dihapus.');
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
            'name' => 'Nama',
            'permissions_count' => 'Hak akses',
            'users_count' => 'Pengguna',
            'guard_name' => 'Guard',
        ];

        $map = static fn (Role $role): array => [
            'name' => $role->name,
            'permissions_count' => $role->permissions_count ?? 0,
            'users_count' => $role->users_count ?? 0,
            'guard_name' => $role->guard_name,
        ];

        return $format === 'pdf'
            ? $export->streamPdf('Peran', $columns, $map, "roles-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "roles-export-{$stamp}.csv");
    }

    /** Every permission of the catalogue: the roles pick from it, and `grantable` says which of them the user holds. */
    private function permissions(): AnonymousResourceCollection
    {
        return PermissionResource::collection(Permission::query()->where('guard_name', 'web')->orderBy('name')->get());
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
