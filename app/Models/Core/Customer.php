<?php

namespace App\Models\Core;

use App\Models\User;
use App\Services\Core\CompanyService;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Customer extends Model implements HasMedia
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use InteractsWithMedia;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    protected static string $factory = CustomerFactory::class;

    /** Values of the derived customer_status column; written only by CustomerStatusRecalculator. */
    public const STATUSES = ['prospective', 'active', 'inactive', 'suspended', 'terminated'];

    protected $fillable = [
        'code',
        'user_id',
        'name',
        'type',
        'email',
        'phone',
        'tax_id',
        'contact_person',
        'area_coverage_id',
        'notes',
        'is_active',
    ];

    protected $attributes = [
        'customer_status' => 'prospective',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'acquired_by_user_id' => 'integer',
        'whatsapp_opt_in_at' => 'datetime',
        'whatsapp_opt_out_at' => 'datetime',
    ];

    /**
     * Consent to business-initiated WhatsApp messages: recorded by staff and not withdrawn since. The
     * whatsapp_opt_* columns are not mass assignable; only SetCustomerWhatsAppConsentAction and the
     * delivery webhook (STOP reply) write them.
     */
    public function hasWhatsAppConsent(): bool
    {
        return $this->whatsapp_opt_in_at !== null && $this->whatsapp_opt_out_at === null;
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /** @return HasMany<ServiceSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(ServiceSubscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The employee credited with acquiring the customer. Not mass assignable: written only by
     * AssignCustomerAcquirerAction. User has no company global scope, so the relation is scoped
     * here and a stray foreign user id never resolves.
     *
     * @return BelongsTo<User, $this>
     */
    public function acquirer(): BelongsTo
    {
        $relation = $this->belongsTo(User::class, 'acquired_by_user_id');
        $companyId = CompanyService::currentId();

        return $companyId === null ? $relation : $relation->where('users.company_id', $companyId);
    }

    public function areaCoverage(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'area_coverage_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('customer')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
