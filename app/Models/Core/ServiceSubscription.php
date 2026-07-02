<?php

namespace App\Models\Core;

use App\Traits\BelongsToCompany;
use Database\Factories\ServiceSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Service\Models\ServicePackage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ServiceSubscription extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'service_package_id',
        'installation_address_id',
        'code',
        'status',
        'activation_date',
        'expiration_date',
        'billing_day',
        'next_invoice_date',
        'ont_asset_id',
        'serving_pop_id',
        'mrc_amount',
        'otc_installation_fee',
        'contract_months',
        'notes',
        'terminated_at',
        'terminated_reason',
    ];

    protected $casts = [
        'activation_date' => 'date',
        'expiration_date' => 'date',
        'billing_day' => 'integer',
        'next_invoice_date' => 'date',
        'mrc_amount' => 'decimal:2',
        'otc_installation_fee' => 'decimal:2',
        'contract_months' => 'integer',
        'terminated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }

    public function installationAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'installation_address_id');
    }

    public function servingPop(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'serving_pop_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('service_subscription')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): ServiceSubscriptionFactory
    {
        return ServiceSubscriptionFactory::new();
    }
}
