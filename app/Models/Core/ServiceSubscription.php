<?php

namespace App\Models\Core;

use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Database\Factories\ServiceSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageVersion;
use Modules\SPK\Models\WorkOrder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ServiceSubscription extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'service_package_id',
        'service_package_version_id',
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
        'suspended_at' => 'datetime',
        'blocked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Sold under the latest version of its package (spec .scratch/master-lifecycle D3). withoutCompany(): seeders and
        // jobs create subscriptions without a tenant context, and the package id was checked against the company already.
        static::creating(function (self $subscription): void {
            $subscription->service_package_version_id ??= ServicePackageVersion::withoutCompany()
                ->where('service_package_id', $subscription->service_package_id)
                ->orderByDesc('version')
                ->value('id');
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<ServicePackage, $this> */
    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class)->withTrashed();
    }

    /** @return BelongsTo<ServicePackageVersion, $this> */
    public function packageVersion(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'service_package_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function installationAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'installation_address_id')->withTrashed();
    }

    public function servingPop(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'serving_pop_id')->withTrashed();
    }

    /** @return BelongsTo<NetworkAsset, $this> */
    public function ontAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class, 'ont_asset_id')->withTrashed();
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'subscription_id');
    }

    /** @return HasOne<NetworkAccount, $this> */
    public function pppoeAccount(): HasOne
    {
        return $this->hasOne(NetworkAccount::class, 'subscription_id')->where('service_type', 'pppoe');
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
