<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Core\EmployeeProfile;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use App\Queries\Admin\UserHistoryQuery;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use App\Services\Core\CompanyService;
use App\Services\Core\UserProfileService;
use App\Support\ExportQuery;
use App\Support\RecordNotFound;
use App\Support\UserDeletionGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'email', 'is_active', 'created_at'];

    /**
     * The members of the company: the users homed here and those granted membership. A member homed elsewhere is
     * listed for his roles here (UserRoleController); his login fields are edited in his home company.
     */
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = $this->filteredQuery($request)
            // The row's abilities compare what the user holds with what the actor holds, in every company the user belongs to:
            // the roles here (`roles`), his own permissions, the roles in every company and the memberships, each loaded once
            // for the page, not per row.
            ->with(['roles.permissions:id,name', 'permissions:id,name', 'rolesInEveryCompany.permissions:id,name', 'companies:companies.id'])
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => UserResource::collection($users),
            'filters' => $request->only(['search', 'is_active', 'sort', 'direction', 'per_page']),
            'roleOptions' => $this->roleOptions($request->user()),
            'can' => [
                'create' => $request->user()?->can('users.manage') ?? false,
                'export' => $request->user()?->can('users.manage') ?? false,
            ],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Admin/Users/Create', [
            'roles' => $this->roleOptions($request->user()),
            'can' => ['createEmployee' => Gate::allows('create', EmployeeProfile::class)],
        ]);
    }

    public function store(StoreUserRequest $request, CompanyRoleService $roles): RedirectResponse
    {
        Gate::authorize('store', User::class);

        $data = $request->validated();
        $names = $data['roles'] ?? [];
        $employee = $data['employee'] ?? null;

        // A profile makes the login an assignable technician (IsActiveTechnician): it is HR's call, not users.manage's.
        if (UserProfileService::hasEmployeeData($employee)) {
            Gate::authorize('create', EmployeeProfile::class);
        }

        unset($data['roles'], $data['employee']);

        $company = CompanyService::current() ?? abort(403);

        // One transaction, the company row locked first: a role the actor may not give (G1) takes the new user and optional employee profile with it.
        $user = $roles->transaction((int) $company->id, function () use ($data, $names, $employee, $company, $request, $roles): User {
            $user = new User($data);
            $user->forceFill([
                'company_id' => $company->id,
                'email_verified_at' => now(),
                'password' => Str::password(32),
            ])->save();
            app(CompanyMembershipService::class)->grant($user, $company, true);
            $roles->syncUserRoles($request->user(), $user, $names);
            UserProfileService::createEmployee($user, $employee);

            return $user;
        });
        $user->notify(new StaffAccountInvitation((int) $company->id));
        AuditService::log('security', 'user.invitation_sent', ['user_id' => $user->id], $user);

        return redirect()->route('admin.users.index')->with('success', 'Pengguna dibuat. Undangan dikirim ke '.$request->string('email')->toString().'.');
    }

    public function show(Request $request, User $user): InertiaResponse
    {
        $this->ensureSameCompany($user);
        Gate::authorize('view', $user);

        $historyAccess = [
            'billing' => $request->user()?->can('billing.view') ?? false,
            'tickets' => $request->user()?->can('ticket.view') ?? false,
            'spk' => $request->user()?->can('spk.view') ?? false,
        ];

        return Inertia::render('Admin/Users/Show', [
            'user' => new UserResource($user->load('roles')),
            'history' => UserHistoryQuery::execute($user, $historyAccess),
            'historyAccess' => $historyAccess,
        ]);
    }

    public function resendInvitation(Request $request, User $user): RedirectResponse
    {
        $this->ensureSameCompany($user);
        Gate::authorize('update', $user);
        abort_unless($request->user()?->can('users.manage'), 403);

        if (! $user->is_active || $user->last_login_at !== null) {
            return back()->withErrors(['invitation' => 'Undangan hanya dapat dikirim kepada pengguna aktif yang belum pernah masuk.']);
        }

        $key = 'staff-invitation|'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 6)) {
            throw new ThrottleRequestsException(
                'Terlalu banyak pengiriman undangan. Coba lagi nanti.',
                headers: ['Retry-After' => RateLimiter::availableIn($key)],
            );
        }

        RateLimiter::hit($key, 60);
        $user->notify(new StaffAccountInvitation((int) CompanyService::currentId()));
        AuditService::log('security', 'user.invitation_resent', ['user_id' => $user->id], $user);

        return back()->with('success', 'Undangan dikirim ulang ke '.$user->email.'.');
    }

    public function edit(Request $request, User $user): InertiaResponse
    {
        $this->ensureSameCompany($user);
        Gate::authorize('edit', $user);

        return Inertia::render('Admin/Users/Edit', [
            'user' => new UserResource($user->load('roles')),
            'roles' => $this->roleOptions($request->user()),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, CompanyRoleService $roles): RedirectResponse
    {
        $this->ensureSameCompany($user);
        Gate::authorize('update', $user);

        $data = $request->validated();
        $names = $data['roles'] ?? [];
        unset($data['roles']);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $deactivating = $user->fill($data)->isDirty('is_active') && ! $user->is_active;
        $save = fn () => $roles->guardingAdministrator((int) CompanyService::currentId(), function () use ($user, $names, $request, $roles): void {
            $user->save();
            // Only the roles that change are written (not syncRoles, which detaches all and re-attaches): the security
            // trail then records exactly what changed.
            $roles->syncUserRoles($request->user(), $user, $names);
        });

        // G5: the company keeps an administrator, so deactivating the last one (or taking his roles) is refused. A login
        // that is deactivated is out of every company he belongs to, so each of them keeps one as well.
        $deactivating ? $roles->guardingAdministratorsOf($user, 'roles', $save) : $save();

        return redirect()->route('admin.users.index')->with('success', 'Pengguna diperbarui.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->ensureSameCompany($user);
        Gate::authorize('delete', $user);

        // One transaction: it locks the companies of the user first and checks that each keeps an administrator (G5).
        UserDeletionGuard::delete($user);

        return back()->with('success', 'Pengguna dihapus.');
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
            'name' => 'Nama',
            'email' => 'Email',
            'roles' => 'Peran',
            'is_active' => 'Status',
        ];

        $map = static fn (User $user): array => [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->implode(', '),
            'is_active' => $user->is_active ? 'Aktif' : 'Nonaktif',
        ];

        return $format === 'pdf'
            ? $export->streamPdf('Pengguna', $columns, $map, "users-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "users-export-{$stamp}.csv");
    }

    private function ensureSameCompany(User $user): void
    {
        RecordNotFound::abortUnless($user->company_id === CompanyService::currentId());
    }

    /**
     * What the forms offer: the company's roles that Pengguna may give, so not the roles of customer accounts (they
     * hold portal.access and belong to CustomerService), and which of them the actor may give himself (G1).
     *
     * @return list<array{name: string, grantable: bool}>
     */
    private function roleOptions(User $actor): array
    {
        $roles = app(CompanyRoleService::class);

        return Role::query()->with('permissions:id,name')->orderBy('name')->get()
            ->reject(fn (Role $role): bool => $roles->isPortal($role))
            ->map(fn (Role $role): array => [
                'name' => $role->name,
                'grantable' => $roles->covers($actor, $role->permissions->pluck('name')->all()),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<User>
     */
    private function filteredQuery(Request $request): Builder
    {
        $companyId = CompanyService::currentId() ?? abort(403);

        $query = User::query()
            ->where(fn (Builder $members) => $members
                ->where('users.company_id', $companyId)
                ->orWhereExists(fn ($membership) => $membership
                    ->from('company_user')
                    ->whereColumn('company_user.user_id', 'users.id')
                    ->where('company_user.company_id', $companyId)))
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
