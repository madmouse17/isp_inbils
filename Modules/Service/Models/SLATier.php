<?php

namespace Modules\Service\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Service\Database\Factories\SLATierFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property string $name
 * @property int $response_time_hours
 * @property int $resolution_time_hours
 * @property string $calendar how the targets are counted: CALENDAR_24H or CALENDAR_BUSINESS
 * @property bool $is_active
 */
class SLATier extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    /** Every hour counts. */
    public const CALENDAR_24H = '24h';

    /** Only the open hours of the company count (companies.settings.service_hours). */
    public const CALENDAR_BUSINESS = 'business';

    public const CALENDARS = [self::CALENDAR_24H, self::CALENDAR_BUSINESS];

    protected $table = 'sla_tiers';

    protected $fillable = [
        'name',
        'response_time_hours',
        'resolution_time_hours',
        'calendar',
        'is_active',
    ];

    protected $attributes = [
        'calendar' => self::CALENDAR_24H,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'response_time_hours' => 'integer',
        'resolution_time_hours' => 'integer',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Tingkat SLA';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    /** A package and every version of it name the tier; the versions keep it from a delete after the package moved on. */
    public static function lifecycleReferences(): ?array
    {
        return [
            'paket' => ['service_packages.sla_tier_id'],
            'versi paket' => ['service_package_versions.sla_tier_id'],
        ];
    }

    public function servicePackages(): HasMany
    {
        return $this->hasMany(ServicePackage::class, 'sla_tier_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sla_tier')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): SLATierFactory
    {
        return SLATierFactory::new();
    }
}
