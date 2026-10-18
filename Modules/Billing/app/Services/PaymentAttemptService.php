<?php

namespace Modules\Billing\Services;

use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Support\Money;
use App\Support\RecordNotFound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Billing\Exceptions\MidtransGatewayException;
use Modules\Billing\Jobs\CancelMidtransTransactionJob;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Notifications\PaymentAttemptExceptionNotification;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Online payment attempts: create-or-reuse for the customer, the one settlement path shared by the Midtrans
 * webhook and the reconciliation command, and the closing of pages an invoice change left unpayable (closeOpenFor).
 *
 * Lock order is always invoice row first, attempt row second, so a Pay click and a notification
 * for the same invoice can never deadlock.
 */
class PaymentAttemptService
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    /** A card the fraud system has not cleared (yet). Nothing is booked. */
    public const CHALLENGE = 'challenge';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    /** Money went back (refund / chargeback): only a human may reverse the payment. */
    public const REVERSED = 'reversed';

    public const IGNORED = 'ignored';

    /** Why Inbils closed a page itself; billing staff read it in the invoice's online payments panel. */
    private const CLOSED_REASON = 'Tagihan dibayar atau dibatalkan saat halaman pembayaran masih terbuka.';

    public function __construct(private readonly MidtransGateway $gateway) {}

    /** Why this invoice cannot be paid online right now, or null when it can. */
    public static function unpayableReason(Invoice $invoice): ?string
    {
        return match (true) {
            $invoice->status === 'draft' => 'Tagihan belum diterbitkan.',
            $invoice->status === 'paid' => 'Tagihan sudah lunas.',
            $invoice->status === 'cancelled' => 'Tagihan sudah dibatalkan.',
            ! in_array($invoice->status, ['sent', 'partial', 'overdue'], true) => 'Tagihan ini tidak dapat dibayar online.',
            Money::compare($invoice->sisa, '0') <= 0 => 'Tagihan tidak memiliki sisa yang harus dibayar.',
            ! str_ends_with($invoice->sisa, '.00') => 'Nominal tagihan memuat pecahan rupiah. Hubungi kami untuk membayar.',
            default => null,
        };
    }

    /**
     * The payment page for the invoice's remaining balance: the open attempt when there is one, else a
     * new attempt with a fresh Snap page. Holds the invoice lock across the Snap call so a double click
     * cannot create two pages; a failed call rolls the new attempt back.
     *
     * @throws ValidationException when the invoice cannot be paid online or Midtrans is unreachable
     */
    public function openFor(Invoice $invoice, string $finishUrl): PaymentAttempt
    {
        abort_unless($this->gateway->isEnabled(), 404);

        return DB::transaction(function () use ($invoice, $finishUrl): PaymentAttempt {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            RecordNotFound::abortUnless($invoice->company_id === (int) CompanyService::currentId());

            if (($reason = self::unpayableReason($invoice)) !== null) {
                throw ValidationException::withMessages(['pay' => $reason]);
            }

            return PaymentAttempt::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', PaymentAttempt::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->whereNotNull('redirect_url')
                ->where('amount', $invoice->sisa)
                ->latest('id')
                ->first() ?? $this->create($invoice, $finishUrl);
        });
    }

    /**
     * Close the open payment pages of an invoice that was just paid or cancelled, or whose balance shrank: none of them
     * can be paid as shown any more. Midtrans is asked to cancel each transaction from the queue, after the commit, so a
     * customer who still has a page open finds nothing to pay. The page of `$exceptOrderId` is the one being settled and
     * stays as it is.
     *
     * Call it inside the transaction that holds the invoice lock, after the change: invoice first, pages second.
     */
    public function closeOpenFor(Invoice $invoice, ?string $exceptOrderId = null): void
    {
        PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentAttempt::STATUS_PENDING)
            ->when($exceptOrderId !== null, fn (Builder $query) => $query->where('order_id', '!=', $exceptOrderId))
            ->lockForUpdate()
            ->get()
            ->each(function (PaymentAttempt $attempt): void {
                $this->record($attempt, PaymentAttempt::STATUS_CANCELLED_LOCALLY, self::CLOSED_REASON, null);
                $this->queueCancel($attempt);
            });
    }

    /**
     * Apply a Midtrans status of the attempt: a notification already re-fetched from the Status API, or
     * the Status API answer itself. Idempotent: once a payment is linked, replays change nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyNotification(PaymentAttempt $attempt, array $payload): PaymentAttempt
    {
        if (($payload['order_id'] ?? null) !== $attempt->order_id) {
            throw new InvalidArgumentException('Midtrans status does not belong to this payment attempt.');
        }

        return DB::transaction(function () use ($attempt, $payload): PaymentAttempt {
            $invoice = Invoice::withoutCompany()->whereKey($attempt->invoice_id)->lockForUpdate()->firstOrFail();
            $attempt = PaymentAttempt::withoutCompany()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $outcome = self::outcomeOf($payload);

            if ($attempt->payment_id !== null) {
                return $this->afterSettlement($attempt, $payload);
            }

            if ($attempt->status === PaymentAttempt::STATUS_CANCELLED_LOCALLY && in_array($outcome, [self::FAILED, self::EXPIRED, self::PENDING, self::CHALLENGE], true)) {
                return $this->noteOnClosedPage($attempt, $outcome, $payload);
            }

            return match ($outcome) {
                self::PAID => $this->settle($attempt, $invoice, $payload),
                self::FAILED => $this->record($attempt, PaymentAttempt::STATUS_FAILED, ($payload['fraud_status'] ?? null) === 'deny' ? 'fraud_status deny' : $this->statusOf($payload), $payload),
                self::EXPIRED => $this->record($attempt, PaymentAttempt::STATUS_EXPIRED, null, $payload),
                self::REVERSED => $this->record($attempt, PaymentAttempt::STATUS_EXCEPTION, 'Midtrans reported '.$this->statusOf($payload).' for an attempt that was never settled here.', $payload),
                self::PENDING, self::CHALLENGE => $this->record($attempt, $attempt->status === PaymentAttempt::STATUS_EXCEPTION ? $attempt->status : PaymentAttempt::STATUS_PENDING, $attempt->status_reason, $payload),
                default => $attempt,
            };
        }, 3);
    }

    /** The attempt's page has expired and Midtrans has no transaction for it. */
    public function markExpired(PaymentAttempt $attempt): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt): PaymentAttempt {
            Invoice::withoutCompany()->whereKey($attempt->invoice_id)->lockForUpdate()->firstOrFail();
            $attempt = PaymentAttempt::withoutCompany()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            return $attempt->status === PaymentAttempt::STATUS_PENDING
                ? $this->record($attempt, PaymentAttempt::STATUS_EXPIRED, 'Payment page expired without a Midtrans transaction.', null)
                : $attempt;
        }, 3);
    }

    /**
     * The exceptions of the current company that a person still has to settle. Money captured and never booked stays open
     * until a payment carrying the order id as its reference exists or Midtrans took all of it back; a payment that was
     * booked and then returned stays open until that payment is reversed.
     *
     * @return Builder<PaymentAttempt>
     */
    public static function openExceptions(): Builder
    {
        return PaymentAttempt::query()
            ->where('status', PaymentAttempt::STATUS_EXCEPTION)
            ->where(fn (Builder $open) => $open
                ->where(fn (Builder $unbooked) => $unbooked
                    ->whereNull('payment_id')
                    ->whereNotExists(Payment::withoutCompany()->select(DB::raw('1'))
                        ->whereColumn('payments.company_id', 'payment_attempts.company_id')
                        ->whereColumn('payments.active_reference', 'payment_attempts.order_id')
                        ->toBase())
                    ->where(fn (Builder $seen) => $seen
                        ->whereNull('last_notification->transaction_status')
                        ->orWhereNotIn('last_notification->transaction_status', ['refund', 'chargeback'])))
                ->orWhere(fn (Builder $returned) => $returned
                    ->whereNotNull('payment_id')
                    ->whereExists(Payment::withoutCompany()->select(DB::raw('1'))
                        ->whereColumn('payments.id', 'payment_attempts.payment_id')
                        ->whereNull('payments.cancelled_at')
                        ->toBase())));
    }

    /**
     * What a Midtrans status means for us. Paid needs status_code 200 and, when present, an accepted
     * fraud status; a card capture needs fraud_status accept.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function outcomeOf(array $payload): string
    {
        $status = $payload['transaction_status'] ?? null;
        $fraud = $payload['fraud_status'] ?? null;
        $paidCode = ($payload['status_code'] ?? null) === '200';

        return match (true) {
            in_array($status, ['deny', 'cancel', 'failure'], true), $fraud === 'deny' => self::FAILED,
            $status === 'settlement' && $paidCode && in_array($fraud, [null, 'accept'], true) => self::PAID,
            $status === 'capture' && $paidCode && $fraud === 'accept' => self::PAID,
            in_array($status, ['capture', 'settlement'], true) => self::CHALLENGE,
            in_array($status, ['pending', 'authorize'], true) => self::PENDING,
            $status === 'expire' => self::EXPIRED,
            in_array($status, ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'], true) => self::REVERSED,
            default => self::IGNORED,
        };
    }

    private function create(Invoice $invoice, string $finishUrl): PaymentAttempt
    {
        $invoice->loadMissing('customer');

        $attempt = PaymentAttempt::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'order_id' => PaymentAttempt::newOrderId(),
            'amount' => $invoice->sisa,
            'status' => PaymentAttempt::STATUS_PENDING,
            'expires_at' => now()->addHours(PaymentAttempt::TTL_HOURS),
        ]);

        try {
            $snap = $this->gateway->createSnapTransaction($attempt, $invoice, $finishUrl);
        } catch (MidtransGatewayException $e) {
            Log::warning('midtrans.snap_unavailable', ['order_id' => $attempt->order_id, 'reason' => $e->getMessage()]);

            throw ValidationException::withMessages(['pay' => 'Halaman pembayaran belum dapat disiapkan. Coba lagi sebentar lagi.']);
        }

        $attempt->update(['snap_token' => $snap['token'], 'redirect_url' => $snap['redirect_url']]);
        $this->audit($attempt, 'created');

        return $attempt;
    }

    /** @param  array<string, mixed>  $payload */
    private function settle(PaymentAttempt $attempt, Invoice $invoice, array $payload): PaymentAttempt
    {
        $gross = $payload['gross_amount'] ?? null;

        if (! is_numeric($gross) || Money::compare($gross, $attempt->amount) !== 0) {
            return $this->record($attempt, PaymentAttempt::STATUS_EXCEPTION, 'gross_amount '.json_encode($gross).' differs from the attempt amount '.$attempt->amount.'.', $payload);
        }

        try {
            $payment = CompanyService::runAs((int) $attempt->company_id, fn (): Payment => BillingService::recordPayment(
                $invoice,
                $attempt->amount,
                'midtrans',
                $attempt->order_id,
                'Midtrans '.$this->text($payload, 'payment_type', 'payment'),
            ));
        } catch (HttpException $e) {
            return $this->record($attempt, PaymentAttempt::STATUS_EXCEPTION, $e->getMessage() ?: 'Billing refused the payment.', $payload);
        }

        return $this->record($attempt, PaymentAttempt::STATUS_PAID, null, $payload, ['payment_id' => $payment->id, 'settled_at' => now()]);
    }

    /**
     * A settled attempt never changes back. The one thing worth noticing later is money returning,
     * which only a human may reverse.
     *
     * @param  array<string, mixed>  $payload
     */
    private function afterSettlement(PaymentAttempt $attempt, array $payload): PaymentAttempt
    {
        $status = $this->statusOf($payload);

        if (! in_array($status, ['refund', 'partial_refund', 'chargeback', 'partial_chargeback', 'cancel'], true)) {
            return $attempt;
        }

        return $this->record($attempt, PaymentAttempt::STATUS_EXCEPTION, 'Midtrans reported '.$status.' after settlement. Reverse the payment by hand if the money was returned.', $payload);
    }

    /**
     * Midtrans keeps talking about a page Inbils closed: its own cancel, an expiry, a customer who still picked a payment
     * method. The page stays closed and what Midtrans said is kept. A pending transaction is cancelled again, because the
     * cancel that closed the page ran before there was one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function noteOnClosedPage(PaymentAttempt $attempt, string $outcome, array $payload): PaymentAttempt
    {
        $attempt = $this->record($attempt, PaymentAttempt::STATUS_CANCELLED_LOCALLY, $attempt->status_reason, $payload);

        if ($outcome === self::PENDING) {
            $this->queueCancel($attempt);
        }

        return $attempt;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $extra
     */
    private function record(PaymentAttempt $attempt, string $status, ?string $reason, ?array $payload, array $extra = []): PaymentAttempt
    {
        $reason = $reason === null ? null : Str::limit($reason, 255, '');
        $seen = [$payload['transaction_status'] ?? null, $payload['fraud_status'] ?? null];
        $changed = $attempt->status !== $status
            || $attempt->status_reason !== $reason
            || $seen !== [data_get($attempt->last_notification, 'transaction_status'), data_get($attempt->last_notification, 'fraud_status')];

        $attempt->update([
            'status' => $status,
            'status_reason' => $reason,
            'transaction_id' => $this->text($payload ?? [], 'transaction_id', $attempt->transaction_id),
            'payment_type' => $this->text($payload ?? [], 'payment_type', $attempt->payment_type),
            'last_notification' => $payload === null ? $attempt->last_notification : Arr::except($payload, ['signature_key']),
            ...$extra,
        ]);

        if ($changed) {
            $this->audit($attempt, match (true) {
                $status === PaymentAttempt::STATUS_PAID => 'settled',
                $payload !== null && in_array($status, [PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_CANCELLED_LOCALLY], true) => 'notified',
                default => $status,
            }, $payload, $reason);
        }

        if ($status === PaymentAttempt::STATUS_EXCEPTION && $changed) {
            Log::warning('midtrans.attempt_exception', ['order_id' => $attempt->order_id, 'reason' => $reason]);
            $this->alertBillingStaff($attempt);
        }

        return $attempt;
    }

    private function queueCancel(PaymentAttempt $attempt): void
    {
        CancelMidtransTransactionJob::dispatch((int) $attempt->company_id, $attempt->id)->afterCommit();
    }

    /** Money that cannot be booked on its own needs a person: the staff who record payments hear of it once the change has committed. */
    private function alertBillingStaff(PaymentAttempt $attempt): void
    {
        DB::afterCommit(function () use ($attempt): void {
            $staff = User::query()
                ->where('company_id', $attempt->company_id)
                ->where('is_active', true)
                ->permission('billing.payment.record')
                ->get();

            Notification::send($staff, new PaymentAttemptExceptionNotification((int) $attempt->company_id, $attempt->id));
        });
    }

    /**
     * Ids and amounts only: never keys, headers or the raw payload.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function audit(PaymentAttempt $attempt, string $event, ?array $payload = null, ?string $reason = null): void
    {
        AuditService::log('payment_attempt', $event, array_filter([
            'order_id' => $attempt->order_id,
            'invoice_id' => $attempt->invoice_id,
            'amount' => $attempt->amount,
            'payment_id' => $attempt->payment_id,
            'transaction_status' => $payload['transaction_status'] ?? null,
            'fraud_status' => $payload['fraud_status'] ?? null,
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null), $attempt);
    }

    /** @param  array<string, mixed>  $payload */
    private function statusOf(array $payload): string
    {
        return $this->text($payload, 'transaction_status', 'unknown');
    }

    /** @param  array<string, mixed>  $payload */
    private function text(array $payload, string $key, ?string $default): ?string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : $default;
    }
}
