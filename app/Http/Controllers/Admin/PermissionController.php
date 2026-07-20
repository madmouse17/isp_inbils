<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('users.manage');

        return Inertia::render('Admin/Permissions/Index', [
            'permissions' => PermissionResource::collection(
                Permission::query()->orderBy('name')->paginate(10)->withQueryString()
            ),
        ]);
    }
}
