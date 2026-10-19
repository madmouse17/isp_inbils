<?php

declare(strict_types=1);

namespace Modules\SPK\Models;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
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
 * @property ?int $organization_unit_id
 * @property ?string $technician_name_snapshot
 * @property ?string $location_code_snapshot
 * @property-read Customer|null $customer
 * @property-read ServiceSubscription|null $subscription
 * @property-read Location|null $location
 * @property-read OrganizationUnit|null $organizationUnit
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
    use HasLifecycle;
    use HasPublicId;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    public const STATUSES = ['draft', 'generated', 'assigned', 'in_progress', 'waiting_review', 'completed', 'rejected', 'cancelled'];

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
        'organization_unit_id', 'technician_name_snapshot', 'location_code_snapshot',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at' => 'datetime',
        'en_route_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // The parents of an SPK stay readable after they are deleted: the SPK is history (spec master-lifecycle D6).
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    /** The technician's unit when the SPK was approved. */
    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id')->withTrashed();
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id')->withTrashed();
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

    /** All a customer is told of the technician: the first name, never the surname or the phone. A completed SPK keeps the name it was approved with. */
    public function technicianFirstName(): ?string
    {
        $name = $this->technician_name_snapshot ?? $this->assignee?->name;

        return $name === null ? null : Str::of($name)->trim()->before(' ')->toString();
    }

    /** The type in Indonesian; a type nobody named keeps its key rather than showing nothing. */
    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public static function lifecycleNoun(): string
    {
        return 'SPK';
    }

    public static function lifecycleFilters(): array
    {
        return self::statusFilters('status', self::STATUSES);
    }

    /** An SPK keeps its own soft-delete rule: only a draft or a cancelled one is deleted, and it can be restored. */
    public static function lifecycleReferences(): ?array
    {
        return null;
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
