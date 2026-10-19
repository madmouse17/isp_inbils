<?php

namespace App\Models\Core;

use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Vehicle extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected static string $factory = VehicleFactory::class;

    protected $fillable = [
        'company_id',
        'plate_number',
        'type',
        'brand',
        'model',
        'purchase_date',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'purchase_date' => 'date',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Kendaraan';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    /** Deleting a vehicle would only clear `employee_profiles.vehicle_id` (SET NULL), so a driver, deleted or not, keeps it. */
    public static function lifecycleReferences(): ?array
    {
        return ['karyawan' => ['employee_profiles.vehicle_id']];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(EmployeeProfile::class, 'vehicle_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('vehicle')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
