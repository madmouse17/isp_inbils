<?php

namespace Modules\Inventory\Models;

use App\Models\Core\Location;
use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property int $location_id
 * @property float|string $old_quantity
 * @property float|string $new_quantity
 * @property float|string $delta
 * @property string $reason
 * @property string $status pending|approved|rejected
 * @property int $requested_by
 * @property ?int $approved_by
 * @property ?Carbon $approved_at
 * @property ?string $rejection_reason
 * @property ?Carbon $created_at
 */
class StockAdjustmentRequest extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $fillable = [
        'company_id',
        'product_id',
        'location_id',
        'old_quantity',
        'new_quantity',
        'delta',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'old_quantity' => 'decimal:2',
        'new_quantity' => 'decimal:2',
        'delta' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

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

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
