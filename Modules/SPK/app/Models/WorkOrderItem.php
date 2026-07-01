<?php

namespace Modules\SPK\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Inventory\Models\Product;

class WorkOrderItem extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'work_order_id', 'product_id', 'quantity_reserved', 'quantity_used', 'note',
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
}
