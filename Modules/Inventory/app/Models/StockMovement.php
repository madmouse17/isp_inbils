<?php

namespace Modules\Inventory\Models;

use App\Models\Core\Location;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property ?int $from_location_id
 * @property ?int $to_location_id
 * @property string $movement_type
 * @property float|string $quantity
 * @property float|string $balance_after
 * @property float|string $reserved_after
 * @property ?string $reference_type
 * @property ?int $reference_id
 * @property ?string $note
 * @property ?int $created_by
 */
class StockMovement extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'product_id',
        'from_location_id',
        'to_location_id',
        'movement_type',
        'quantity',
        'balance_after',
        'reserved_after',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'reserved_after' => 'decimal:2',
    ];

    public $timestamps = true;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('StockMovement ledger entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('StockMovement ledger entries are immutable and cannot be deleted.');
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
