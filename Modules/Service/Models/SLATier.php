<?php

namespace Modules\Service\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Service\Database\Factories\SLATierFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SLATier extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'sla_tiers';

    protected $fillable = [
        'name',
        'uptime_pct',
        'response_time_hours',
        'resolution_time_hours',
        'credit_pct',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'uptime_pct' => 'decimal:2',
        'response_time_hours' => 'integer',
        'resolution_time_hours' => 'integer',
        'credit_pct' => 'decimal:2',
    ];

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
