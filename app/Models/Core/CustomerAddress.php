<?php

namespace App\Models\Core;

use App\Support\AuditProperties;
use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
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
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CustomerAddress extends Model
{
    use BelongsToCompany {
        tapActivity as private stampCompany;
    }
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
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

    public static function lifecycleNoun(): string
    {
        return 'Alamat';
    }

    /** No active flag: a used address cannot be hidden, only an unused one goes. */
    public static function lifecycleFilters(): array
    {
        return [];
    }

    public static function lifecycleReferences(): ?array
    {
        return ['langganan' => ['service_subscriptions.installation_address_id']];
    }

    protected static function booted(): void
    {
        static::restored(fn (self $address) => $address->settleMarkers());
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ServiceSubscription::class, 'installation_address_id');
    }

    /**
     * An address hidden before the delete became permanent comes back with the markers it had then, while another one
     * may hold them now. The customer keeps exactly one primary and one installation point.
     */
    private function settleMarkers(): void
    {
        $others = static::withoutCompany()->where('customer_id', $this->customer_id)->whereKeyNot($this->getKey());

        foreach (['is_primary', 'is_installation_point'] as $marker) {
            $heldElsewhere = (clone $others)->where($marker, true)->exists();

            if ($this->{$marker} === $heldElsewhere) {
                $this->{$marker} = ! $heldElsewhere;
            }
        }

        if ($this->isDirty()) {
            $this->save();
        }
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

    /** The diff of an address says that it changed, never where it is: street line, coordinates and notes stay out. */
    public function tapActivity(ActivityContract $activity, string $eventName): void
    {
        $this->stampCompany($activity, $eventName);

        AuditProperties::maskValues($activity, ['address', 'lat', 'lng', 'notes']);
    }

    protected static function newFactory(): CustomerAddressFactory
    {
        return CustomerAddressFactory::new();
    }
}
