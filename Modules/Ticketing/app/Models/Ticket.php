<?php

namespace Modules\Ticketing\Models;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\SPK\Models\WorkOrder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $title
 * @property ?string $description
 * @property string $source
 * @property int $category_id
 * @property string $status
 * @property string $priority
 * @property ?int $customer_id
 * @property ?int $subscription_id
 * @property ?int $network_asset_id
 * @property ?int $location_id
 * @property ?int $assigned_to
 * @property ?int $spawned_spk_id
 * @property ?Carbon $sla_deadline
 * @property ?Carbon $response_deadline
 * @property ?Carbon $response_due_notified_at
 * @property ?Carbon $sla_breached_notified_at
 * @property ?Carbon $first_response_at
 * @property ?Carbon $resolved_at
 * @property ?Carbon $closed_at
 * @property ?string $resolution_note
 * @property int $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read bool $is_sla_breached
 * @property-read bool $is_response_breached
 * @property-read TicketCategory|null $category
 * @property-read Customer|null $customer
 * @property-read ServiceSubscription|null $subscription
 * @property-read NetworkAsset|null $networkAsset
 * @property-read Location|null $location
 * @property-read User|null $assignee
 * @property-read WorkOrder|null $spawnedSpk
 * @property-read User|null $creator
 * @property-read Collection<int, TicketComment> $comments
 */
class Ticket extends Model implements HasMedia
{
    use BelongsToCompany;
    use HasFactory;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'title', 'description', 'source', 'category_id', 'status', 'priority',
        'customer_id', 'subscription_id', 'network_asset_id', 'location_id',
        'assigned_to', 'spawned_spk_id', 'sla_deadline', 'response_deadline', 'first_response_at',
        'resolved_at', 'closed_at', 'resolution_note', 'created_by',
    ];

    protected $casts = [
        'sla_deadline' => 'datetime',
        'response_deadline' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class);
    }

    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function spawnedSpk(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'spawned_spk_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'ticket_id');
    }

    public function getIsSlaBreachedAttribute(): bool
    {
        if (in_array($this->status, ['resolved', 'closed'])) {
            return false;
        }

        return $this->sla_deadline !== null && $this->sla_deadline->isPast();
    }

    /** Late when the first response came after the target, or when none has come yet and the target has passed. */
    public function getIsResponseBreachedAttribute(): bool
    {
        if ($this->response_deadline === null) {
            return false;
        }

        return ($this->first_response_at ?? now())->greaterThan($this->response_deadline);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ticket')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
