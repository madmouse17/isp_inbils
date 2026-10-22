<?php

namespace Modules\Service\Services;

use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;

/** The package picker options, grouped by category. */
class ServicePackageOptions
{
    /** Category codes listed first per customer type; the rest follow by sort_order. */
    private const PREFERRED = [
        'Individual' => ['RUMAHAN'],
        'Company' => ['BISNIS', 'KORPORAT'],
    ];

    /**
     * Active packages of active categories, one group per category with packages.
     *
     * @return list<array{category: array{id: string, code: string, name: string}, packages: list<array{value: string, label: string, description: string}>}>
     */
    public static function grouped(?string $customerType): array
    {
        $preferred = self::PREFERRED[(string) $customerType] ?? [];
        $packages = ServicePackage::query()
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'public_id', 'code', 'name', 'price_mrc', 'service_package_category_id'])
            ->groupBy('service_package_category_id');

        return ServicePackageCategory::query()
            ->whereIn('id', $packages->keys())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->sortBy(function (ServicePackageCategory $category) use ($preferred): int {
                $position = array_search($category->code, $preferred, true);

                return $position === false ? count($preferred) : $position;
            })
            ->values()
            ->map(fn (ServicePackageCategory $category): array => [
                'category' => ['id' => $category->public_id, 'code' => $category->code, 'name' => $category->name],
                'packages' => $packages[$category->id]->map(fn (ServicePackage $package): array => [
                    'value' => $package->public_id,
                    'label' => $package->name,
                    'description' => $package->code.' · Rp '.number_format((float) $package->price_mrc, 0, ',', '.').'/bln',
                ])->all(),
            ])
            ->all();
    }
}
