<?php

namespace Modules\Reporting\Models;

use App\Models\Core\ServiceSubscription;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;

/**
 * Read-only view of the `customers` table for the Sales KPI. The shared Core Customer must not know Billing, so
 * the relations the report aggregates (`invoices`, `payments`) are declared here, on a model nothing writes.
 * Queries on it scope the company explicitly (`forCompany`) and read soft-deleted customers on purpose.
 */
final class SalesCustomer extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'customers';

    protected $fillable = [];

    protected $casts = [
        'acquired_by_user_id' => 'integer',
    ];

    /** Writes go through the Core Customer; this view refuses to persist. */
    protected static function booted(): void
    {
        self::saving(fn (): bool => false);
        self::deleting(fn (): bool => false);
    }

    /** @return HasMany<ServiceSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(ServiceSubscription::class, 'customer_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'customer_id');
    }

    /**
     * Payments of the customer's invoices. Payments of soft-deleted invoices are left out by the
     * through-model soft-delete scope.
     *
     * @return HasManyThrough<Payment, Invoice, $this>
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Invoice::class, 'customer_id', 'invoice_id');
    }
}
