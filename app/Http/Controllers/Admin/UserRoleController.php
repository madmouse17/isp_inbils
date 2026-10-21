<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRolesRequest;
use App\Models\User;
use App\Services\Core\CompanyRoleService;
use Illuminate\Http\RedirectResponse;

/**
 * The roles of one member in the company the user acts in. It also serves a member homed elsewhere, whose login
 * fields UserController does not reach: his roles here are this company's to give (UserPolicy::assignRoles).
 */
class UserRoleController extends Controller
{
    public function __invoke(UpdateUserRolesRequest $request, User $user, CompanyRoleService $roles): RedirectResponse
    {
        $roles->syncUserRoles($request->user(), $user, $request->roleNames());

        return back()->with('success', 'Peran pengguna diperbarui.');
    }
}
