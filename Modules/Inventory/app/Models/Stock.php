<?php

namespace Modules\Inventory\Models;

use App\Models\Core\Location;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property int $location_id
 * @property float|string $quantity
 * @property float|string $reserved_quantity
 * @property-read Product|null $product
 * @property-read Location|null $location
 * @property-read float $available
 */
class Stock extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'company_id',
        'product_id',
        'location_id',
        'quantity',
        'reserved_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'reserved_quantity' => 'decimal:2',
    ];

    public $timestamps = true;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function getAvailableAttribute(): float
    {
        return (float) $this->quantity - (float) $this->reserved_quantity;
    }
}
