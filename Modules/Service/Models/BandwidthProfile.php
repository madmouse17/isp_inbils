<?php

namespace Modules\Service\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Service\Database\Factories\BandwidthProfileFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BandwidthProfile extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'download_mbps',
        'upload_mbps',
        'type',
        'contention_ratio',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'download_mbps' => 'integer',
        'upload_mbps' => 'integer',
        'contention_ratio' => 'integer',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Profil bandwidth';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    /** A package and every version of it name the profile; the versions keep it from a delete after the package moved on. */
    public static function lifecycleReferences(): ?array
    {
        return [
            'paket' => ['service_packages.bandwidth_profile_id'],
            'versi paket' => ['service_package_versions.bandwidth_profile_id'],
        ];
    }

    public function servicePackages(): HasMany
    {
        return $this->hasMany(ServicePackage::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('bandwidth_profile')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): BandwidthProfileFactory
    {
        return BandwidthProfileFactory::new();
    }
}
