<?php

namespace Modules\Billing\Models;

use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Support\Money;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\SPK\Models\WorkOrder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $company_id
 * @property string $number
 * @property string $type
 * @property string $source
 * @property int $customer_id
 * @property ?int $work_order_id
 * @property ?int $subscription_id
 * @property string $status
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $discount_amount
 * @property string $total
 * @property string $paid_amount
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property Carbon|null $created_at
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Collection<int, Payment> $payments
 */
class Invoice extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use LogsActivity;
    use SoftDeletes;

    public const CUSTOMER_VISIBLE_STATUSES = ['sent', 'partial', 'paid', 'overdue', 'cancelled'];

    protected $fillable = [
        'company_id', 'number', 'type', 'source', 'customer_id', 'subscription_id', 'work_order_id',
        'issue_date', 'due_date', 'billing_period_start', 'billing_period_end',
        'status', 'subtotal', 'tax_amount', 'discount_amount', 'total', 'paid_amount',
        'notes', 'created_by', 'sent_at', 'cancelled_at', 'cancel_reason', 'cancelled_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    /** @return HasMany<PaymentAttempt, $this> */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'invoice_id');
    }

    public function getSisaAttribute(): string
    {
        return Money::round(Money::sub($this->total, $this->paid_amount));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('invoice')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
