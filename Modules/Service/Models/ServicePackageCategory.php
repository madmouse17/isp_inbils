<?php

namespace Modules\Service\Models;

use App\Support\PublicId;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Service\Database\Factories\ServicePackageCategoryFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 */
class ServicePackageCategory extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use LogsActivity;

    /** code => [name, sort_order]: every company starts with these; the codes drive the picker order per customer type. */
    public const DEFAULTS = [
        'RUMAHAN' => ['Rumahan', 1],
        'BISNIS' => ['Bisnis', 2],
        'KORPORAT' => ['Korporat', 3],
    ];

    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Creates the missing default categories of a company.
     *
     * @return array<string, int> category code => id
     */
    public static function ensureDefaults(int $companyId): array
    {
        foreach (self::DEFAULTS as $code => [$name, $sortOrder]) {
            DB::table('service_package_categories')->insertOrIgnore([
                'public_id' => PublicId::new(), 'company_id' => $companyId, 'code' => $code, 'name' => $name, 'sort_order' => $sortOrder,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return DB::table('service_package_categories')->where('company_id', $companyId)->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    }

    /** @return HasMany<ServicePackage, $this> */
    public function servicePackages(): HasMany
    {
        return $this->hasMany(ServicePackage::class, 'service_package_category_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('service_package_category')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): ServicePackageCategoryFactory
    {
        return ServicePackageCategoryFactory::new();
    }
}
