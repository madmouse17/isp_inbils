<?php

namespace App\Models\Core;

use App\Traits\BelongsToCompany;
use Database\Factories\CustomerContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CustomerContact extends Model
{
    use BelongsToCompany;
    use HasFactory;
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
        return $this->belongsTo(Customer::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('customer_contact')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
