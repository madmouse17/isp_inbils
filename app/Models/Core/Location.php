<?php

namespace App\Models\Core;

use App\Services\Core\LocationService;
use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class Location extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected static function booted(): void
    {
        // A location deleted before an ancestor was renamed or moved comes back under its parent's current path.
        static::restored(fn (self $location) => LocationService::recomputePath($location));
    }

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'type',
        'path',
        'address',
        'lat',
        'lng',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Lokasi';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    /** Every table that points at a location, trashed rows included: stock, assets, SPK, tickets and the rest keep it from a hard delete. */
    public static function lifecycleReferences(): ?array
    {
        return [
            'lokasi turunan' => ['locations.parent_id'],
            'pelanggan' => ['customers.area_coverage_id'],
            'langganan' => ['service_subscriptions.serving_pop_id'],
            'stok' => ['stocks.location_id'],
            'mutasi stok' => ['stock_movements.from_location_id', 'stock_movements.to_location_id'],
            'permintaan barang' => ['inventory_requests.warehouse_location_id', 'inventory_request_items.source_location_id'],
            'retur material' => ['material_return_cases.warehouse_location_id'],
            'penyesuaian stok' => ['stock_adjustment_requests.location_id'],
            'VLAN' => ['vlans.location_id'],
            'prefiks IP' => ['ip_prefixes.location_id'],
            'aset jaringan' => ['network_assets.location_id'],
            'riwayat pemasangan' => ['network_asset_installations.location_id'],
            'SPK' => ['work_orders.location_id'],
            'tiket' => ['tickets.location_id'],
            'pengumuman' => ['announcement_location.location_id'],
            'banner promo' => ['location_promo_banner.location_id'],
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('location')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if ($eventName !== 'updated') {
            return;
        }

        $properties = $activity->properties?->toArray() ?? [];
        if (! isset($properties['attributes'])) {
            return;
        }

        $attributes = $properties['attributes'];
        $old = $properties['old'] ?? [];

        $activity->properties = collect([
            'old' => $old,
            'new' => $attributes,
        ])->merge(collect($properties)->except(['attributes', 'old']));
    }
}
