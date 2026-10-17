<?php

namespace Modules\Ticketing\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $code
 * @property int $default_sla_hours
 * @property int $default_response_hours
 * @property string $default_priority
 * @property ?string $customer_hint a short tip the customer app shows before it offers to open a ticket
 * @property bool $is_active
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read Collection<int, Ticket> $tickets
 */
class TicketCategory extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'code', 'default_sla_hours', 'default_response_hours', 'default_priority', 'customer_hint', 'is_active',
    ];

    protected $casts = [
        'default_sla_hours' => 'integer',
        'default_response_hours' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ticket_category')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
