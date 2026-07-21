<?php

namespace Modules\Inventory\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Database\Factories\ProductFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Product extends Model
{
    use BelongsToCompany;
    use HasFactory;
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
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
