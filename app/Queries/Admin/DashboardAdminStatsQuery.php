<?php

namespace App\Queries\Admin;

use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Database\Eloquent\Model;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Company statistics that only actors who administer users, roles or the company may see on the
 * dashboard. Role and Permission are the documented tenant-wide exceptions to company scoping; each
 * module link also needs the permission that opens its page, so no count is shown without a way in.
 */
final class DashboardAdminStatsQuery
{
    private const RECENT_ACTIVITY_LIMIT = 10;

    /** @var list<array{name: string, route: string, model: class-string<Model>, permissions: list<string>}> */
    private const MODULES = [
        ['name' => 'Pelanggan', 'route' => 'admin.customers.index', 'model' => Customer::class, 'permissions' => ['customer.view']],
        ['name' => 'Layanan', 'route' => 'admin.service-packages.index', 'model' => ServicePackage::class, 'permissions' => ['service.view', 'service.manage']],
        ['name' => 'Inventaris', 'route' => 'admin.products.index', 'model' => Product::class, 'permissions' => ['inventory.view']],
        ['name' => 'Aset jaringan', 'route' => 'admin.network-assets.index', 'model' => NetworkAsset::class, 'permissions' => ['network_asset.view']],
        ['name' => 'SPK', 'route' => 'admin.spk.index', 'model' => WorkOrder::class, 'permissions' => ['spk.view']],
        ['name' => 'Tagihan', 'route' => 'admin.invoices.index', 'model' => Invoice::class, 'permissions' => ['billing.view']],
        ['name' => 'Tiket', 'route' => 'admin.tickets.index', 'model' => Ticket::class, 'permissions' => ['ticket.view']],
    ];

    public static function isEligible(User $user): bool
    {
        return $user->can('users.manage') || $user->can('roles.manage') || $user->can('company.manage');
    }

    /**
     * @return array{
     *     userCount: int,
     *     activeUserCount: int,
     *     roleCount: int,
     *     permissionCount: int,
     *     recentActivity: list<array{id: string, log_name: string|null, description: string, created_at: string|null}>,
     *     modules: list<array{name: string, href: string, count: int}>
     * }
     */
    public static function stats(User $user): array
    {
        $companyId = CompanyService::idFor($user);
        abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

        return [
            'userCount' => User::query()->where('company_id', $companyId)->count(),
            'activeUserCount' => User::query()->where('company_id', $companyId)->where('is_active', true)->count(),
            'roleCount' => Role::query()->count(),
            'permissionCount' => Permission::query()->count(),
            'recentActivity' => Activity::query()
                ->where('company_id', $companyId)
                ->where(fn ($logs) => $logs->whereNull('log_name')->orWhere('log_name', '<>', PlatformAuditService::PLATFORM_LOG))
                ->latest()
                ->limit(self::RECENT_ACTIVITY_LIMIT)
                ->get()
                ->map(fn (Activity $activity): array => [
                    'id' => $activity->public_id,
                    'log_name' => $activity->log_name,
                    'description' => $activity->description,
                    'created_at' => $activity->created_at?->toISOString(),
                ])
                ->all(),
            'modules' => collect(self::MODULES)
                ->filter(fn (array $module): bool => collect($module['permissions'])->contains(fn (string $permission): bool => $user->can($permission)))
                ->map(fn (array $module): array => [
                    'name' => $module['name'],
                    'href' => route($module['route']),
                    'count' => $module['model']::query()->where('company_id', $companyId)->count(),
                ])
                ->values()
                ->all(),
        ];
    }
}
