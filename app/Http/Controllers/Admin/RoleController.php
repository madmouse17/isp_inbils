<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
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
                'export' => $request->user()?->can('roles.export') ?? false,
            ],
        ]);
    }

    public function show(Role $role): InertiaResponse
    {
        Gate::authorize('view', $role);

        return Inertia::render('Admin/Roles/Show', [
            'role' => new RoleResource($role->load('permissions')->loadCount(['users' => fn (Builder $users) => $users->where('users.company_id', CompanyService::currentId())])),
            'permissions' => PermissionResource::collection(Permission::query()->orderBy('name')->get()),
        ]);
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

    /**
     * @return Builder<Role>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Role::query()
            ->with('permissions')
            ->withCount(['permissions', 'users' => fn (Builder $users) => $users->where('users.company_id', CompanyService::currentId())])
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
