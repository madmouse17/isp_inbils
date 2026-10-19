<?php

namespace Modules\Billing\Console;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Billing\Exceptions\MidtransGatewayException;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\MidtransGateway;
use Modules\Billing\Services\PaymentAttemptService;

/**
 * The backstop for lost or late webhooks: ask Midtrans about attempts still pending after a grace period, and about pages
 * Inbils closed until a day after they expire (money can still reach one), and feed the answer through the same path the
 * webhook uses.
 */
class ReconcileMidtransPayments extends Command
{
    /** Minutes a pending attempt is left to its webhook before Midtrans is asked about it. */
    private const GRACE_MINUTES = 10;

    /** Days after its expiry a page Inbils closed is still asked about: a transaction opened on it just before can be paid later. */
    private const CLOSED_PAGE_DAYS = 1;

    protected $signature = 'midtrans:reconcile {--company= : Explicit company id for artisan runs} {--dry-run : Report what would change without writing}';

    protected $description = 'Settle or expire pending Midtrans payment attempts whose notification never arrived, and flag money that reached a page Inbils closed';

    public function __construct(
        private readonly MidtransGateway $gateway,
        private readonly PaymentAttemptService $attempts,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $totals = ['checked' => 0, 'settled' => 0, 'expired' => 0, 'exceptions' => 0, 'failed' => 0, 'still pending' => 0, 'closed' => 0, 'errors' => 0];
        $configured = 0;

        foreach ($this->resolveCompanyIds() as $companyId) {
            CompanyService::runAs($companyId, function () use ($dryRun, &$totals, &$configured): void {
                // Each company asks Midtrans with its own key; one without a merchant account has nothing to ask about.
                if (! $this->gateway->isEnabled()) {
                    return;
                }

                $configured++;
                $this->attemptsToAsk()->chunkById(100, function ($attempts) use ($dryRun, &$totals): void {
                    foreach ($attempts as $attempt) {
                        $totals['checked']++;
                        $totals[$this->reconcile($attempt, $dryRun)]++;
                    }
                });
            });
        }

        if ($configured === 0) {
            $this->info('Midtrans is not configured; nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->table(array_map('ucfirst', array_keys($totals)), [array_values($totals)]);
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Midtrans reconciliation complete.');

        return self::SUCCESS;
    }

    /** @return Builder<PaymentAttempt> */
    private function attemptsToAsk(): Builder
    {
        return PaymentAttempt::query()
            ->where(fn ($query) => $query
                ->where('status', PaymentAttempt::STATUS_PENDING)
                ->orWhere(fn ($closed) => $closed
                    ->where('status', PaymentAttempt::STATUS_CANCELLED_LOCALLY)
                    ->where('expires_at', '>', now()->subDays(self::CLOSED_PAGE_DAYS))))
            ->where(fn ($query) => $query
                ->where('created_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
                ->orWhere('expires_at', '<=', now()));
    }

    /**
     * @return 'settled'|'expired'|'exceptions'|'failed'|'still pending'|'closed'|'errors'
     */
    private function reconcile(PaymentAttempt $attempt, bool $dryRun): string
    {
        try {
            $status = $this->gateway->transactionStatus($attempt->order_id);

            if ($status === null) {
                // Midtrans knows nothing of it: the customer never chose a payment method.
                if ($attempt->status === PaymentAttempt::STATUS_CANCELLED_LOCALLY) {
                    return 'closed'; // a page Inbils closed stays as it is: it is never expired
                }

                // Only an expired page is closed.
                if (! $attempt->expires_at->isPast()) {
                    return 'still pending';
                }

                $this->line(($dryRun ? 'would expire' : 'expired').' '.$attempt->order_id);

                if (! $dryRun) {
                    $this->attempts->markExpired($attempt);
                }

                return 'expired';
            }

            if ($dryRun) {
                $outcome = PaymentAttemptService::outcomeOf($status);
                $this->line('would apply '.$outcome.' to '.$attempt->order_id);

                return match (true) {
                    $outcome === PaymentAttemptService::PAID => 'settled',
                    $outcome === PaymentAttemptService::REVERSED => 'exceptions',
                    $attempt->status === PaymentAttempt::STATUS_CANCELLED_LOCALLY => 'closed',
                    $outcome === PaymentAttemptService::EXPIRED => 'expired',
                    $outcome === PaymentAttemptService::FAILED => 'failed',
                    default => 'still pending',
                };
            }

            return match ($this->attempts->applyNotification($attempt, $status)->status) {
                PaymentAttempt::STATUS_PAID => 'settled',
                PaymentAttempt::STATUS_EXPIRED => 'expired',
                PaymentAttempt::STATUS_EXCEPTION => 'exceptions',
                PaymentAttempt::STATUS_FAILED => 'failed',
                PaymentAttempt::STATUS_CANCELLED_LOCALLY => 'closed',
                default => 'still pending',
            };
        } catch (MidtransGatewayException|InvalidArgumentException $e) {
            // One unreachable or unverifiable attempt must not stop the others; it stays as it is for the next run.
            Log::warning('midtrans.reconcile_failed', ['order_id' => $attempt->order_id, 'reason' => $e->getMessage()]);

            return 'errors';
        }
    }

    /** @return list<int> */
    private function resolveCompanyIds(): array
    {
        $explicit = $this->option('company');

        if ($explicit !== null) {
            return [(int) $explicit];
        }

        return Company::where('is_active', true)->pluck('id')->all();
    }
}
