<?php

namespace App\Models\Core;

use App\Traits\BelongsToCompany;
use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravolt\Indonesia\Models\City;
use Laravolt\Indonesia\Models\District;
use Laravolt\Indonesia\Models\Province;
use Laravolt\Indonesia\Models\Village;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CustomerAddress extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'label',
        'address',
        'city',
        'postal_code',
        'province_code',
        'city_code',
        'district_code',
        'village_code',
        'lat',
        'lng',
        'is_installation_point',
        'is_primary',
        'notes',
    ];

    protected $casts = [
        'is_installation_point' => 'boolean',
        'is_primary' => 'boolean',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ServiceSubscription::class, 'installation_address_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }

    public function regionCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_code', 'code');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_code', 'code');
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_code', 'code');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('customer_address')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): CustomerAddressFactory
    {
        return CustomerAddressFactory::new();
    }
}
