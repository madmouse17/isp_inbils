<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('users.manage');

        $query = Permission::query()
            ->when($request->input('search'), function (Builder $query, string $value): void {
                $term = trim($value);

                if ($term !== '') {
                    $query->where('name', 'like', '%'.$term.'%');
                }
            });

        return Inertia::render('Admin/Permissions/Index', [
            'permissions' => PermissionResource::collection(
                $this->applySort($query, $request, 'name')
                    ->paginate($this->perPage($request))
                    ->withQueryString()
            ),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
        ]);
    }
}
