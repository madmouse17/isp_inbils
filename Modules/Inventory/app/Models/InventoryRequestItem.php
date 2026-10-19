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
 * @property int $inventory_request_id
 * @property int $product_id
 * @property ?int $source_location_id
 * @property float $quantity_requested
 * @property float $quantity_approved
 * @property float $quantity_issued
 * @property ?string $note
 * @property-read InventoryRequest|null $inventoryRequest
 * @property-read Product|null $product
 * @property-read Location|null $sourceLocation
 */
class InventoryRequestItem extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $table = 'inventory_request_items';

    protected $fillable = [
        'company_id',
        'inventory_request_id',
        'product_id',
        'source_location_id',
        'quantity_requested',
        'quantity_approved',
        'quantity_issued',
        'note',
    ];

    protected $casts = [
        'quantity_requested' => 'float',
        'quantity_approved' => 'float',
        'quantity_issued' => 'float',
    ];

    public function inventoryRequest(): BelongsTo
    {
        return $this->belongsTo(InventoryRequest::class, 'inventory_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id')->withTrashed();
    }
}
