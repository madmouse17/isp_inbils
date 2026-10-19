<?php

namespace Modules\Inventory\Models;

use App\Models\Core\Location;
use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

/**
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property int $requested_by
 * @property ?int $approved_by
 * @property ?int $prepared_by
 * @property ?int $ready_by
 * @property ?int $issued_by
 * @property ?int $rejected_by
 * @property ?int $cancelled_by
 * @property ?int $warehouse_location_id
 * @property ?string $source_type
 * @property ?int $source_id
 * @property string $status
 * @property ?Carbon $needed_at
 * @property ?string $reason
 * @property ?string $approval_note
 * @property ?string $rejection_reason
 * @property ?string $cancellation_reason
 * @property ?Carbon $submitted_at
 * @property ?Carbon $approved_at
 * @property ?Carbon $prepared_at
 * @property ?Carbon $ready_at
 * @property ?Carbon $issued_at
 * @property ?Carbon $rejected_at
 * @property ?Carbon $cancelled_at
 * @property-read User|null $requester
 * @property-read User|null $approver
 * @property-read User|null $preparer
 * @property-read User|null $readyUser
 * @property-read User|null $issuer
 * @property-read User|null $rejecter
 * @property-read User|null $canceller
 * @property-read Location|null $warehouseLocation
 * @property-read Collection<int, InventoryRequestItem> $items
 */
class InventoryRequest extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $table = 'inventory_requests';

    protected $fillable = [
        'company_id',
        'code',
        'requested_by',
        'approved_by',
        'prepared_by',
        'ready_by',
        'issued_by',
        'rejected_by',
        'cancelled_by',
        'warehouse_location_id',
        'source_type',
        'source_id',
        'status',
        'needed_at',
        'reason',
        'approval_note',
        'rejection_reason',
        'cancellation_reason',
        'submitted_at',
        'approved_at',
        'prepared_at',
        'ready_at',
        'issued_at',
        'rejected_at',
        'cancelled_at',
    ];

    protected $casts = [
        'needed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'prepared_at' => 'datetime',
        'ready_at' => 'datetime',
        'issued_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function readyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ready_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'warehouse_location_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryRequestItem::class, 'inventory_request_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * What a request is for. The column holds `work_order` or `ticket`, not a class name, so `source` is not
     * resolved through the morph relation: `loadSources` sets the record as the relation `source` instead.
     *
     * @return array<string, class-string<Model>>
     */
    public static function sourceModels(): array
    {
        return ['work_order' => WorkOrder::class, 'ticket' => Ticket::class];
    }

    /**
     * Loads the record each request is for (a deleted one too) in one query per type, unless it is loaded already.
     *
     * @param  Collection<int, self>  $requests
     */
    public static function loadSources(Collection $requests): void
    {
        foreach (self::sourceModels() as $type => $model) {
            $matching = $requests->where('source_type', $type)->reject(fn (self $request): bool => $request->relationLoaded('source'));

            if ($matching->isEmpty()) {
                continue;
            }

            $records = $model::query()->withTrashed()->whereKey($matching->pluck('source_id')->unique())->get()->keyBy('id');

            $matching->each(fn (self $request) => $request->setRelation('source', $records->get($request->source_id)));
        }
    }

    public function sourcePublicId(): ?string
    {
        if ($this->source_type === null) {
            return null;
        }

        self::loadSources(new Collection([$this]));

        return $this->relationLoaded('source') ? $this->getRelation('source')?->public_id : null;
    }
}
