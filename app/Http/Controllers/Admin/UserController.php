<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->where('company_id', CompanyService::currentId())
            ->with('roles')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => UserResource::collection($users),
            'can' => [
                'create' => $request->user()?->can('users.manage') ?? false,
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

        return back()->with('success', 'User created.');
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

        return back()->with('success', 'User updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->ensureSameCompany($user);
        Gate::authorize('delete', $user);

        $user->delete();

        return back()->with('success', 'User deleted.');
    }

    private function ensureSameCompany(User $user): void
    {
        abort_unless($user->company_id === CompanyService::currentId(), 404);
    }
}
