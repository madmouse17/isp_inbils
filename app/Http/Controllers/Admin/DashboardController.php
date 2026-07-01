<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $company = CompanyService::current();
        $companyId = $request->user()?->company_id;

        return Inertia::render('Admin/Dashboard/Index', [
            'company' => $company,
            'userCount' => User::query()->where('company_id', $companyId)->count(),
            'roleCount' => Role::query()->count(),
            'activeUserCount' => User::query()->where('company_id', $companyId)->where('is_active', true)->count(),
            'permissionCount' => Permission::query()->count(),
            'recentActivity' => Activity::query()
                ->when($companyId, fn ($query) => $query->where('properties->company_id', $companyId))
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (Activity $activity): array => [
                    'id' => $activity->id,
                    'log_name' => $activity->log_name,
                    'description' => $activity->description,
                    'created_at' => $activity->created_at?->toISOString(),
                ]),
            'modulePlaceholders' => [
                ['name' => 'Customer', 'phase' => 'Phase 2', 'count' => 0],
                ['name' => 'Service', 'phase' => 'Phase 2', 'count' => 0],
                ['name' => 'Inventory', 'phase' => 'Phase 3', 'count' => 0],
                ['name' => 'Network Asset', 'phase' => 'Phase 3', 'count' => 0],
                ['name' => 'SPK', 'phase' => 'Phase 4', 'count' => 0],
                ['name' => 'Billing', 'phase' => 'Phase 5', 'count' => 0],
                ['name' => 'Ticketing', 'phase' => 'Phase 6', 'count' => 0],
            ],
        ]);
    }
}
