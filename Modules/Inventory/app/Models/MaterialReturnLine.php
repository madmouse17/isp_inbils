<?php

namespace Modules\Inventory\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $material_return_case_id
 * @property int $inventory_request_item_id
 * @property int $product_id
 * @property string|float $issued_qty
 * @property string|float $actual_used_qty
 * @property string|float $declared_good_return_qty
 * @property string|float $declared_discrepancy_qty
 * @property string|float $accepted_good_return_qty
 * @property string|float $damaged_return_qty
 * @property string|float $lost_qty
 * @property string|float $other_discrepancy_qty
 * @property ?string $discrepancy_code
 * @property ?string $discrepancy_reason
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read MaterialReturnCase $case
 * @property-read InventoryRequestItem $inventoryRequestItem
 * @property-read Product $product
 */
class MaterialReturnLine extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $table = 'material_return_lines';

    protected $fillable = [
        'company_id',
        'material_return_case_id',
        'inventory_request_item_id',
        'product_id',
        'issued_qty',
        'actual_used_qty',
        'declared_good_return_qty',
        'declared_discrepancy_qty',
        'accepted_good_return_qty',
        'damaged_return_qty',
        'lost_qty',
        'other_discrepancy_qty',
        'discrepancy_code',
        'discrepancy_reason',
    ];

    protected $casts = [
        'issued_qty' => 'decimal:2',
        'actual_used_qty' => 'decimal:2',
        'declared_good_return_qty' => 'decimal:2',
        'declared_discrepancy_qty' => 'decimal:2',
        'accepted_good_return_qty' => 'decimal:2',
        'damaged_return_qty' => 'decimal:2',
        'lost_qty' => 'decimal:2',
        'other_discrepancy_qty' => 'decimal:2',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(MaterialReturnCase::class, 'material_return_case_id');
    }

    public function inventoryRequestItem(): BelongsTo
    {
        return $this->belongsTo(InventoryRequestItem::class, 'inventory_request_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }
}
