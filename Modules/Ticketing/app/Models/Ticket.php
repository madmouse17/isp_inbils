<?php

namespace Modules\Ticketing\Models;

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
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\SPK\Models\WorkOrder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Ticket extends Model implements HasMedia
{
    use BelongsToCompany;
    use HasFactory;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'code', 'title', 'description', 'source', 'category_id', 'status', 'priority',
        'customer_id', 'subscription_id', 'network_asset_id', 'location_id',
        'assigned_to', 'spawned_spk_id', 'sla_deadline', 'first_response_at',
        'resolved_at', 'closed_at', 'resolution_note', 'created_by',
    ];

    protected $casts = [
        'sla_deadline' => 'datetime',
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ticket')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
