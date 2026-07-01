<?php

namespace Modules\SPK\Models;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WorkOrder extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'code', 'type', 'title', 'description', 'status',
        'customer_id', 'subscription_id', 'location_id', 'assigned_to',
        'ticket_id', 'source', 'priority', 'scheduled_date',
        'started_at', 'completed_at', 'result', 'rejection_reason', 'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at' => 'datetime',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'work_order_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkOrderAssignment::class, 'work_order_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(WorkOrderEvidence::class, 'work_order_id');
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
