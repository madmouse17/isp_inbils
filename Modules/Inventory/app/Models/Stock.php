<?php

namespace Modules\Inventory\Models;

use App\Models\Core\Location;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Stock extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
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
