<?php

namespace Modules\NetworkAsset\Models;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\Inventory\Models\Product;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $company_id
 * @property ?int $product_id
 * @property string $code
 * @property string $name
 * @property string $asset_type
 * @property string $status
 * @property ?int $location_id
 * @property ?int $customer_id
 * @property ?int $subscription_id
 * @property ?string $serial_number
 * @property ?string $mac_address
 * @property ?string $ip_address
 * @property ?string $management_ip
 * @property string $ownership owned|leased
 * @property ?string $vendor
 * @property ?string $model
 * @property ?string $template_key the catalogue row the port layout was copied from; null for a custom layout
 * @property ?array<string, mixed> $port_layout the panel layout snapshot (shape: Modules\NetworkAsset\Support\PanelLayout)
 * @property ?int $rack_asset_id the rack the device is mounted in
 * @property ?int $rack_unit lowest rack unit the device occupies, U1 at the bottom
 * @property ?int $height_u device height in rack units; for a rack, its size
 * @property ?CarbonInterface $purchase_date
 * @property ?string $purchase_price
 * @property ?CarbonInterface $warranty_expiry
 * @property ?string $notes
 * @property ?CarbonInterface $installed_at
 * @property ?CarbonInterface $retired_at
 */
class NetworkAsset extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    /** The statuses an asset moves through (resources/js/lib/statuses/network.ts); also the ?status= values of the index. */
    public const STATUSES = ['available', 'installed', 'maintenance', 'damaged', 'retired'];

    protected $fillable = [
        'product_id',
        'code',
        'name',
        'asset_type',
        'serial_number',
        'mac_address',
        'ip_address',
        'management_ip',
        'location_id',
        'customer_id',
        'subscription_id',
        'status',
        'ownership',
        'vendor',
        'model',
        'purchase_date',
        'purchase_price',
        'warranty_expiry',
        'notes',
        'installed_at',
        'retired_at',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_price' => 'decimal:2',
        'warranty_expiry' => 'date',
        'installed_at' => 'datetime',
        'retired_at' => 'datetime',
        'port_layout' => 'array',
        'rack_unit' => 'integer',
        'height_u' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function (NetworkAsset $asset) {
            if ($asset->isForceDeleting() && $asset->installations()->exists()) {
                throw new LogicException('Cannot hard delete network asset with installation history.');
            }
        });
    }

    public static function lifecycleNoun(): string
    {
        return 'Aset jaringan';
    }

    public static function lifecycleFilters(): array
    {
        return self::statusFilters('status', self::STATUSES);
    }

    /**
     * An asset is retired (Pensiunkan), never deactivated. Anything that ever pointed at it keeps it from a delete:
     * the installation history and the other rows below. The ONT of a subscription has no foreign key but is history too.
     * The asset's IP addresses are no reference: the delete releases them (IpAssignmentService::releaseForAsset).
     */
    public static function lifecycleReferences(): ?array
    {
        return [
            'riwayat pemasangan' => ['network_asset_installations.network_asset_id'],
            'item SPK' => ['work_order_items.network_asset_id'],
            'tiket' => ['tickets.network_asset_id'],
            'splitter ODN' => ['odn_splitters.network_asset_id'],
            'port ODN' => ['odn_ports.network_asset_id'],
            'perangkat NAS' => ['nas_devices.network_asset_id'],
            'langganan' => ['service_subscriptions.ont_asset_id'],
            'port perangkat' => ['asset_ports.network_asset_id'],
            'perangkat di rak' => ['network_assets.rack_asset_id'],
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<ServiceSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class)->withTrashed();
    }

    public function installations(): HasMany
    {
        return $this->hasMany(NetworkAssetInstallation::class);
    }

    /**
     * The port register rows of a router, switch, OLT uplink or ONT; the scoped port routes resolve through it.
     *
     * @return HasMany<AssetPort, $this>
     */
    public function ports(): HasMany
    {
        return $this->hasMany(AssetPort::class);
    }

    /** @return BelongsTo<NetworkAsset, $this> */
    public function rack(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rack_asset_id')->withTrashed();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('network_asset')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
