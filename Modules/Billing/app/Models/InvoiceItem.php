<?php

namespace Modules\Billing\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Product;

/**
 * @property int $id
 * @property int $invoice_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_amount
 * @property string $tax_rate
 * @property string $line_total
 */
class InvoiceItem extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'company_id', 'invoice_id', 'product_id', 'description', 'quantity', 'unit_price',
        'discount_amount', 'tax_rate', 'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id')->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
