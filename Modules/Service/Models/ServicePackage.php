<?php

namespace Modules\Service\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\Concerns\RecordsVersions;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** @property string $name */
class ServicePackage extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use RecordsVersions;
    use SoftDeletes;

    /** The column default: a package saved without an installation fee has none to copy into its first version. */
    protected $attributes = [
        'price_otc' => 0,
    ];

    protected $fillable = [
        'code',
        'name',
        'bandwidth_profile_id',
        'speed_profile_id',
        'sla_tier_id',
        'service_package_category_id',
        'price_mrc',
        'price_otc',
        'contract_min_months',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_mrc' => 'decimal:2',
        'price_otc' => 'decimal:2',
        'contract_min_months' => 'integer',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Paket layanan';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    /** The versions are owned by the package (cascade); only the subscriptions that were sold under it keep it from a delete. */
    public static function lifecycleReferences(): ?array
    {
        return ['langganan' => ['service_subscriptions.service_package_id']];
    }

    public function bandwidthProfile(): BelongsTo
    {
        return $this->belongsTo(BandwidthProfile::class)->withTrashed();
    }

    public function speedProfile(): BelongsTo
    {
        return $this->belongsTo(SpeedProfile::class)->withTrashed();
    }

    /** @return BelongsTo<SLATier, $this> */
    public function slaTier(): BelongsTo
    {
        return $this->belongsTo(SLATier::class, 'sla_tier_id')->withTrashed();
    }

    /** @return BelongsTo<ServicePackageCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServicePackageCategory::class, 'service_package_category_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany('App\\Models\\Core\\ServiceSubscription');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('service_package')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): ServicePackageFactory
    {
        return ServicePackageFactory::new();
    }
}
