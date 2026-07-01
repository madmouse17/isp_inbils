<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
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
            'modules' => [
                ['name' => 'Customer', 'href' => route('admin.customers.index'), 'count' => Customer::query()->where('company_id', $companyId)->count()],
                ['name' => 'Service', 'href' => route('admin.service-packages.index'), 'count' => ServicePackage::query()->where('company_id', $companyId)->count()],
                ['name' => 'Inventory', 'href' => route('admin.products.index'), 'count' => Product::query()->where('company_id', $companyId)->count()],
                ['name' => 'Network Asset', 'href' => route('admin.network-assets.index'), 'count' => NetworkAsset::query()->where('company_id', $companyId)->count()],
                ['name' => 'SPK', 'href' => route('admin.spk.index'), 'count' => WorkOrder::query()->where('company_id', $companyId)->count()],
                ['name' => 'Billing', 'href' => route('admin.invoices.index'), 'count' => Invoice::query()->where('company_id', $companyId)->count()],
                ['name' => 'Ticketing', 'href' => route('admin.tickets.index'), 'count' => Ticket::query()->where('company_id', $companyId)->count()],
            ],
        ]);
    }
}
