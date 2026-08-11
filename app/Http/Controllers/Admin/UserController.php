<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'email', 'is_active', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = $this->filteredQuery($request)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => UserResource::collection($users),
            'filters' => $request->only(['search', 'is_active', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => $request->user()?->can('users.manage') ?? false,
                'export' => $request->user()?->can('users.manage') ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Admin/Users/Create', [
            'roles' => RoleResource::collection(Role::query()->orderBy('name')->get()),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        Gate::authorize('store', User::class);

        $data = $request->validated();
        $roles = $data['roles'] ?? [];
        unset($data['roles']);

        $user = new User($data);
        $user->forceFill([
            'company_id' => CompanyService::currentId(),
            'email_verified_at' => now(),
        ])->save();
        $user->syncRoles($roles);

        return redirect()->route('admin.users.index')->with('success', 'User created.');
    }

    public function show(User $user): Response
    {
        $this->ensureSameCompany($user);
        Gate::authorize('view', $user);

        return Inertia::render('Admin/Users/Show', [
            'user' => new UserResource($user->load('roles')),
        ]);
    }

    public function edit(User $user): Response
    {
        $this->ensureSameCompany($user);
        Gate::authorize('edit', $user);

        return Inertia::render('Admin/Users/Edit', [
            'user' => new UserResource($user->load('roles')),
            'roles' => RoleResource::collection(Role::query()->orderBy('name')->get()),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->ensureSameCompany($user);
        Gate::authorize('update', $user);

        $data = $request->validated();
        $roles = $data['roles'] ?? [];
        unset($data['roles']);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);
        $user->syncRoles($roles);

        return redirect()->route('admin.users.index')->with('success', 'User updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->ensureSameCompany($user);
        Gate::authorize('delete', $user);

        $user->delete();

        return back()->with('success', 'User deleted.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('users.manage');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('name', 'asc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'name' => 'Name',
            'email' => 'Email',
            'roles' => 'Roles',
            'is_active' => 'Status',
        ];

        $map = static fn (User $user): array => [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->implode(', '),
            'is_active' => $user->is_active ? 'Active' : 'Inactive',
        ];

        return $format === 'pdf'
            ? $export->streamPdf('Users', $columns, $map, "users-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "users-export-{$stamp}.csv");
    }

    /**
     * @return Builder<User>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = User::query()
            ->where('company_id', CompanyService::currentId())
            ->with('roles')
            ->when($request->input('search'), function (Builder $query, string $value): void {
                $term = trim($value);

                if ($term === '') {
                    return;
                }

                $query->where(function (Builder $sub) use ($term): void {
                    $like = '%'.$term.'%';
                    $sub->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            });

        $status = $request->input('is_active');
        if ($status !== null && $status !== '') {
            $normalized = strtolower((string) $status);

            if (in_array($normalized, ['1', 'true', 'active'], true)) {
                $query->where('is_active', true);
            } elseif (in_array($normalized, ['0', 'false', 'inactive'], true)) {
                $query->where('is_active', false);
            }
        }

        return $this->applySort($query, $request, 'name');
    }
}
