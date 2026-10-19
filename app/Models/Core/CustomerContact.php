<?php

namespace App\Models\Core;

use App\Support\AuditProperties;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Database\Factories\CustomerContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CustomerContact extends Model
{
    use BelongsToCompany {
        tapActivity as private stampCompany;
    }
    use HasFactory;
    use HasPublicId;
    use LogsActivity;

    protected static function newFactory()
    {
        return CustomerContactFactory::new();
    }

    protected $fillable = [
        'customer_id',
        'name',
        'role',
        'phone',
        'email',
        'is_primary',
        'notes',
        'company_id',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('customer_contact')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** The diff of a contact says which personal field changed, never what it held. */
    public function tapActivity(ActivityContract $activity, string $eventName): void
    {
        $this->stampCompany($activity, $eventName);

        AuditProperties::maskValues($activity, ['phone', 'email', 'notes']);
    }
}
