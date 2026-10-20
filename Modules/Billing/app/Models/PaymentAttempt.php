<?php

namespace Modules\Billing\Models;

use App\Models\User;
use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;

/**
 * One try to pay an invoice online. The immutable `payments` ledger is written only when an
 * attempt settles; every other outcome stays here.
 *
 * @property int $id
 * @property int $company_id
 * @property int $invoice_id
 * @property string $order_id
 * @property string $amount
 * @property string $status pending|paid|failed|expired|exception|cancelled_locally|refunded
 * @property string|null $status_reason
 * @property string|null $snap_token
 * @property string|null $redirect_url
 * @property string|null $transaction_id
 * @property string|null $payment_type
 * @property array<string, mixed>|null $last_notification
 * @property Carbon $expires_at
 * @property Carbon|null $settled_at
 * @property int|null $payment_id
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_reference
 * @property string|null $resolution_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PaymentAttempt extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    public const TTL_HOURS = 24;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_EXCEPTION = 'exception';

    /** Inbils closed the page: the invoice was paid or cancelled, or its balance shrank, while the Snap page was open. */
    public const STATUS_CANCELLED_LOCALLY = 'cancelled_locally';

    /** Finance closed an exception whose money went back outside Inbils (K4). Nothing Midtrans says later opens it again. */
    public const STATUS_REFUNDED = 'refunded';

    /** Columns that decide what money an attempt stands for; they never change after creation. */
    private const IMMUTABLE = ['company_id', 'invoice_id', 'order_id', 'amount'];

    protected $fillable = [
        'company_id', 'invoice_id', 'order_id', 'amount', 'status', 'status_reason', 'snap_token',
        'redirect_url', 'transaction_id', 'payment_type', 'last_notification', 'expires_at',
        'settled_at', 'payment_id', 'resolved_by', 'resolved_at', 'resolution_reference', 'resolution_note',
    ];

    /** The Snap token and its URL let anyone pay this invoice: they must never ride along in a serialised model. */
    protected $hidden = ['snap_token', 'redirect_url', 'last_notification'];

    protected $casts = [
        'amount' => 'decimal:2',
        'last_notification' => 'array',
        'expires_at' => 'datetime',
        'settled_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PaymentAttempt $attempt) {
            if ($attempt->isDirty(self::IMMUTABLE)) {
                throw new LogicException('Payment attempt financial details are immutable and cannot be updated.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Payment attempts are an audit trail and cannot be deleted.');
        });
    }

    /** Midtrans takes one order_id per merchant, and the merchant is shared by every company. It names neither one. */
    public static function newOrderId(): string
    {
        return 'INB-'.Str::ulid();
    }

    /** Snap wants the amount as a whole number of rupiah. */
    public function grossAmount(): int
    {
        return (int) Str::before((string) $this->amount, '.');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id')->withTrashed();
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * Who marked an exception as refunded.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    protected static function newFactory(): PaymentAttemptFactory
    {
        return PaymentAttemptFactory::new();
    }
}
