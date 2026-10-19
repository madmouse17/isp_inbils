<?php

namespace Modules\Inventory\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Database\Factories\ProductFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $company_id
 * @property ?int $category_id
 * @property ?int $unit_id
 * @property string $sku
 * @property string $name
 * @property ?string $description
 * @property string $type
 * @property bool $track_stock
 * @property string $sell_price
 * @property string $cost_price
 * @property string $min_stock
 * @property bool $is_active
 */
class Product extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasLifecycle;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'unit_id',
        'sku',
        'name',
        'description',
        'type',
        'track_stock',
        'sell_price',
        'cost_price',
        'min_stock',
        'is_active',
    ];

    protected $casts = [
        'track_stock' => 'boolean',
        'is_active' => 'boolean',
        'sell_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'min_stock' => 'decimal:2',
    ];

    public static function lifecycleNoun(): string
    {
        return 'Barang';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    /**
     * The stock balances cascade with the product and are not counted: ProductController::destroy refuses a product
     * that still holds quantity.
     */
    public static function lifecycleReferences(): ?array
    {
        return [
            'mutasi stok' => ['stock_movements.product_id'],
            'permintaan barang' => ['inventory_request_items.product_id'],
            'retur material' => ['material_return_lines.product_id'],
            'penyesuaian stok' => ['stock_adjustment_requests.product_id'],
            'item SPK' => ['work_order_items.product_id'],
            'aset jaringan' => ['network_assets.product_id'],
            'item tagihan' => ['invoice_items.product_id'],
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('product')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
