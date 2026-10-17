<?php

declare(strict_types=1);

namespace Modules\SPK\Models;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $type
 * @property string $title
 * @property ?string $description
 * @property string $status
 * @property ?int $customer_id
 * @property ?int $subscription_id
 * @property ?int $location_id
 * @property ?int $assigned_to
 * @property ?int $ticket_id
 * @property string $source
 * @property string $priority
 * @property ?Carbon $scheduled_date
 * @property ?Carbon $started_at
 * @property ?Carbon $en_route_at
 * @property ?Carbon $submitted_at
 * @property ?Carbon $completed_at
 * @property ?string $result
 * @property ?string $rejection_reason
 * @property ?int $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?int $approved_by
 * @property ?Carbon $approved_at
 * @property ?int $rejected_by
 * @property ?Carbon $rejected_at
 * @property-read Customer|null $customer
 * @property-read ServiceSubscription|null $subscription
 * @property-read Location|null $location
 * @property-read User|null $assignee
 * @property-read User|null $creator
 * @property-read User|null $approver
 * @property-read User|null $rejector
 * @property-read Collection<int, WorkOrderItem> $items
 * @property-read Collection<int, WorkOrderAssignment> $assignments
 */
class WorkOrder extends Model implements HasMedia
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    /** The ISP's words for each type, the same in the technician and the customer app. */
    public const TYPE_LABELS = [
        'installation' => 'Instalasi',
        'maintenance' => 'Perawatan',
        'upgrade_service' => 'Peningkatan layanan',
        'relocation' => 'Pindah lokasi',
    ];

    protected $fillable = [
        'code', 'type', 'title', 'description', 'status',
        'customer_id', 'subscription_id', 'location_id', 'assigned_to',
        'ticket_id', 'source', 'priority', 'scheduled_date',
        'started_at', 'en_route_at', 'submitted_at', 'completed_at', 'result', 'rejection_reason', 'created_by',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at' => 'datetime',
        'en_route_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'work_order_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkOrderAssignment::class, 'work_order_id');
    }

    /** All a customer is told of the technician: the first name, never the surname or the phone. */
    public function technicianFirstName(): ?string
    {
        return $this->assignee === null ? null : Str::of($this->assignee->name)->trim()->before(' ')->toString();
    }

    /** The type in Indonesian; a type nobody named keeps its key rather than showing nothing. */
    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('work_order')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
