<?php

namespace Modules\Ticketing\Models;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasLifecycle;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 * @property ?Carbon $response_breached_notified_at
 * @property ?Carbon $sla_breached_notified_at
 * @property ?Carbon $first_response_at
 * @property ?string $first_response_source 'reply' (a public staff comment) or 'start' (the Mulai button); null until the first response
 * @property ?Carbon $resolved_at
 * @property ?Carbon $reopened_at when a customer reply last brought the resolved ticket back
 * @property ?Carbon $closed_at
 * @property ?string $resolution_note
 * @property ?string $category_name_snapshot the category name as it was when the ticket closed; null until then
 * @property ?string $assignee_name_snapshot the handler name as it was when the ticket closed; null until then
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
    use HasLifecycle;
    use HasPublicId;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    public const STATUSES = ['open', 'assigned', 'on_progress', 'resolved', 'closed'];

    /** A resolved or closed ticket owes no response and no resolution any more. */
    public const FINISHED_STATUSES = ['resolved', 'closed'];

    protected $fillable = [
        'company_id', 'code', 'title', 'description', 'source', 'category_id', 'status', 'priority',
        'customer_id', 'subscription_id', 'network_asset_id', 'location_id',
        'assigned_to', 'spawned_spk_id', 'sla_deadline', 'response_deadline', 'first_response_at', 'first_response_source',
        'resolved_at', 'reopened_at', 'closed_at', 'resolution_note', 'category_name_snapshot', 'assignee_name_snapshot', 'created_by',
    ];

    protected $casts = [
        'sla_deadline' => 'datetime',
        'response_deadline' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reopened_at' => 'datetime',
        'response_breached_notified_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** The parents of a ticket are history: one deleted since still shows (spec .scratch/master-lifecycle D6). */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id')->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class)->withTrashed();
    }

    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function spawnedSpk(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'spawned_spk_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'ticket_id');
    }

    /**
     * Unfinished tickets whose unanswered first response, or whose resolution, falls due within $minutes from now.
     * A target already passed is a breach, not a near miss: the `sla_breached` list holds those.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeDueSoon(Builder $query, int $minutes = 30): Builder
    {
        $now = now();
        $limit = now()->addMinutes($minutes);

        return $query
            ->whereNotIn('status', self::FINISHED_STATUSES)
            ->where(fn (Builder $due) => $due
                ->where(fn (Builder $response) => $response->whereNull('first_response_at')->whereBetween('response_deadline', [$now, $limit]))
                ->orWhereBetween('sla_deadline', [$now, $limit]));
    }

    /**
     * Unfinished tickets whose newest public comment was written by the ticket's own customer account, so nobody on
     * staff has answered since. A comment is newer by its id (they are only ever appended). An internal note is no
     * answer, a deleted comment is gone, and a customer without an app login never matches.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeAwaitingStaffReply(Builder $query): Builder
    {
        return $query
            ->whereNotIn('status', self::FINISHED_STATUSES)
            ->whereExists(fn (QueryBuilder $reply) => $reply->from('ticket_comments')
                ->whereColumn('ticket_comments.ticket_id', 'tickets.id')
                ->where('ticket_comments.is_internal', false)
                ->whereNull('ticket_comments.deleted_at')
                ->whereIn('ticket_comments.author_id', fn (QueryBuilder $account) => $account->from('customers')->select('user_id')->whereColumn('customers.id', 'tickets.customer_id'))
                ->whereNotExists(fn (QueryBuilder $later) => $later->from('ticket_comments', 'later')
                    ->whereColumn('later.ticket_id', 'tickets.id')
                    ->where('later.is_internal', false)
                    ->whereNull('later.deleted_at')
                    ->whereColumn('later.id', '>', 'ticket_comments.id')));
    }

    public static function lifecycleNoun(): string
    {
        return 'Tiket';
    }

    public static function lifecycleFilters(): array
    {
        return self::statusFilters('status', self::STATUSES);
    }

    /** A ticket keeps its own soft-delete rule: only a closed one is deleted, and it can be restored. */
    public static function lifecycleReferences(): ?array
    {
        return null;
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
