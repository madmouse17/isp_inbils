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
 * The backstop for lost or late webhooks: ask Midtrans about attempts still pending after a grace
 * period and feed the answer through the same path the webhook uses.
 */
class ReconcileMidtransPayments extends Command
{
    /** Minutes a pending attempt is left to its webhook before Midtrans is asked about it. */
    private const GRACE_MINUTES = 10;

    protected $signature = 'midtrans:reconcile {--company= : Explicit company id for artisan runs} {--dry-run : Report what would change without writing}';

    protected $description = 'Settle or expire pending Midtrans payment attempts whose notification never arrived';

    public function __construct(
        private readonly MidtransGateway $gateway,
        private readonly PaymentAttemptService $attempts,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->gateway->isEnabled()) {
            $this->info('Midtrans is not configured; nothing to reconcile.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totals = ['checked' => 0, 'settled' => 0, 'expired' => 0, 'exceptions' => 0, 'failed' => 0, 'still pending' => 0, 'errors' => 0];

        foreach ($this->resolveCompanyIds() as $companyId) {
            CompanyService::runAs($companyId, function () use ($dryRun, &$totals): void {
                $this->pendingAttempts()->chunkById(100, function ($attempts) use ($dryRun, &$totals): void {
                    foreach ($attempts as $attempt) {
                        $totals['checked']++;
                        $totals[$this->reconcile($attempt, $dryRun)]++;
                    }
                });
            });
        }

        $this->table(array_map('ucfirst', array_keys($totals)), [array_values($totals)]);
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Midtrans reconciliation complete.');

        return self::SUCCESS;
    }

    /** @return Builder<PaymentAttempt> */
    private function pendingAttempts(): Builder
    {
        return PaymentAttempt::query()
            ->where('status', PaymentAttempt::STATUS_PENDING)
            ->where(fn ($query) => $query
                ->where('created_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
                ->orWhere('expires_at', '<=', now()));
    }

    /**
     * @return 'settled'|'expired'|'exceptions'|'failed'|'still pending'|'errors'
     */
    private function reconcile(PaymentAttempt $attempt, bool $dryRun): string
    {
        try {
            $status = $this->gateway->transactionStatus($attempt->order_id);

            if ($status === null) {
                // Midtrans knows nothing of it: the customer never chose a payment method. Only an expired page is closed.
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

                return match ($outcome) {
                    PaymentAttemptService::PAID => 'settled',
                    PaymentAttemptService::EXPIRED => 'expired',
                    PaymentAttemptService::FAILED => 'failed',
                    PaymentAttemptService::REVERSED => 'exceptions',
                    default => 'still pending',
                };
            }

            return match ($this->attempts->applyNotification($attempt, $status)->status) {
                PaymentAttempt::STATUS_PAID => 'settled',
                PaymentAttempt::STATUS_EXPIRED => 'expired',
                PaymentAttempt::STATUS_EXCEPTION => 'exceptions',
                PaymentAttempt::STATUS_FAILED => 'failed',
                default => 'still pending',
            };
        } catch (MidtransGatewayException|InvalidArgumentException $e) {
            // One unreachable or unverifiable attempt must not stop the others; it stays pending for the next run.
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
