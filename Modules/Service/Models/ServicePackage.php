<?php

namespace Modules\Service\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ServicePackage extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'bandwidth_profile_id',
        'speed_profile_id',
        'sla_tier_id',
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

    public function bandwidthProfile(): BelongsTo
    {
        return $this->belongsTo(BandwidthProfile::class);
    }

    public function speedProfile(): BelongsTo
    {
        return $this->belongsTo(SpeedProfile::class);
    }

    public function slaTier(): BelongsTo
    {
        return $this->belongsTo(SLATier::class, 'sla_tier_id');
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
}
