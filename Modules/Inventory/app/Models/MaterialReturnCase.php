<?php

namespace Modules\Inventory\Models;

use App\Models\Core\Location;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\SPK\Models\WorkOrder;

/**
 * @property int $id
 * @property int $company_id
 * @property int $inventory_request_id
 * @property ?int $work_order_id
 * @property ?int $warehouse_location_id
 * @property int $technician_id
 * @property string $status
 * @property int $declaration_version
 * @property ?int $declared_by
 * @property ?Carbon $declared_at
 * @property ?int $received_by
 * @property ?Carbon $received_at
 * @property ?int $reconciled_by
 * @property ?Carbon $reconciled_at
 * @property ?int $rejected_by
 * @property ?Carbon $rejected_at
 * @property ?string $rejection_reason
 * @property ?int $resolved_by
 * @property ?Carbon $resolved_at
 * @property ?string $resolution_note
 * @property ?string $idempotency_key
 * @property ?string $payload_hash
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read InventoryRequest $inventoryRequest
 * @property-read WorkOrder|null $workOrder
 * @property-read Location|null $warehouseLocation
 * @property-read User $technician
 * @property-read User|null $declarer
 * @property-read User|null $receiver
 * @property-read User|null $reconciler
 * @property-read User|null $rejecter
 * @property-read User|null $resolver
 * @property-read Collection<int, MaterialReturnLine> $lines
 * @property-read Collection<int, MaterialReturnEvent> $events
 */
class MaterialReturnCase extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'material_return_cases';

    protected $fillable = [
        'company_id',
        'inventory_request_id',
        'work_order_id',
        'warehouse_location_id',
        'technician_id',
        'status',
        'declaration_version',
        'declared_by',
        'declared_at',
        'received_by',
        'received_at',
        'reconciled_by',
        'reconciled_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'resolved_by',
        'resolved_at',
        'resolution_note',
        'idempotency_key',
        'payload_hash',
    ];

    protected $casts = [
        'declaration_version' => 'integer',
        'declared_at' => 'datetime',
        'received_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'rejected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function inventoryRequest(): BelongsTo
    {
        return $this->belongsTo(InventoryRequest::class, 'inventory_request_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'warehouse_location_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialReturnLine::class, 'material_return_case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MaterialReturnEvent::class, 'material_return_case_id');
    }
}
