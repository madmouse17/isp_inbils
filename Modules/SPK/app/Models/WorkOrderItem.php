<?php

namespace Modules\SPK\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Models\NetworkAsset;

/**
 * @property int $id
 * @property int $work_order_id
 * @property int $product_id
 * @property ?int $network_asset_id
 * @property string|float|int|null $quantity_reserved
 * @property string|float|int|null $quantity_used
 * @property ?string $note
 */
class WorkOrderItem extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'work_order_id', 'product_id', 'network_asset_id', 'quantity_reserved', 'quantity_used', 'note',
    ];

    protected $casts = [
        'quantity_reserved' => 'decimal:2',
        'quantity_used' => 'decimal:2',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class, 'network_asset_id');
    }
}
