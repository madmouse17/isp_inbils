<?php

namespace Modules\Billing\Models;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Billing\Services\BillingService;

/**
 * @property int $id
 * @property int $company_id
 * @property int $invoice_id
 * @property string $amount
 * @property string $method
 * @property string|null $reference
 * @property Carbon $paid_at
 * @property int|null $received_by
 * @property string|null $notes
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $cancelled_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Payment extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /**
     * Internal authorization guard: only true while executing within BillingService::reversePayment().
     */
    private static bool $inCanonicalReversal = false;

    protected $fillable = [
        'company_id', 'invoice_id', 'amount', 'method', 'reference', 'paid_at',
        'received_by', 'notes', 'cancelled_at', 'cancel_reason', 'cancelled_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if ($payment->cancelled_at !== null || $payment->cancelled_by !== null || $payment->cancel_reason !== null) {
                throw new LogicException('New payments cannot be created in a cancelled state.');
            }
        });

        static::updating(function (Payment $payment) {
            if ($payment->getOriginal('cancelled_at') !== null) {
                throw new LogicException('Cancelled payment cannot be modified.');
            }

            if ($payment->isDirty(['amount', 'company_id', 'invoice_id', 'method', 'reference', 'paid_at', 'received_by'])) {
                throw new LogicException('Payment financial details are immutable and cannot be updated.');
            }

            if ($payment->isDirty(['cancelled_at', 'cancel_reason', 'cancelled_by'])) {
                if (! self::$inCanonicalReversal || DB::transactionLevel() === 0) {
                    throw new LogicException('Direct payment cancellation is forbidden. Payments must be reversed via BillingService::reversePayment().');
                }
            }
        });

        static::deleting(function () {
            throw new LogicException('Payment records are immutable and cannot be deleted.');
        });
    }

    /** Only a gateway order id is safe to show a customer; a staff-typed reference on a manual payment stays internal. */
    public function customerReference(): ?string
    {
        return $this->method === 'midtrans' ? $this->reference : null;
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            'midtrans' => 'Pembayaran online',
            'transfer' => 'Transfer bank',
            'cash' => 'Tunai',
            'cheque' => 'Cek',
            'other' => 'Lainnya',
            default => Str::headline((string) $this->method),
        };
    }

    /**
     * Authorize and execute a canonical payment reversal operation.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function executeInCanonicalReversal(callable $callback): mixed
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Canonical payment reversal must run within an active database transaction.');
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $isAuthorizedCaller = false;
        foreach ($trace as $frame) {
            if (isset($frame['class'])
                && $frame['class'] === BillingService::class
                && $frame['function'] === 'reversePayment'
            ) {
                $isAuthorizedCaller = true;
                break;
            }
        }

        if (! $isAuthorizedCaller) {
            throw new LogicException('Payment reversal authorization can only be granted by BillingService::reversePayment().');
        }

        self::$inCanonicalReversal = true;

        try {
            return $callback();
        } finally {
            self::$inCanonicalReversal = false;
        }
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
