<?php

namespace Modules\Billing\Services;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\SubscriptionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Automatic isolir and reconnection. Callers run it inside CompanyService::runAs() for the company.
 *
 * An invoice is "past grace" when it is unpaid and its due_date is more than N days ago. The rule reads
 * due_date, not the status, because a partial payment turns an overdue invoice back into "partial" until
 * the next billing:check-overdue run.
 */
class OverdueSuspensionService
{
    private const DEFAULT_GRACE_DAYS = 7;

    /**
     * Suspend every active subscription with an invoice past grace, with source "overdue". A dry run only
     * reports them. Each subscription is its own transaction, so one conflict never blocks the others.
     *
     * @return list<array{id: int, code: string}> the subscriptions suspended (or that would be)
     */
    public static function suspendOverdue(int $companyId, bool $dryRun = false): array
    {
        $settings = self::settings($companyId);

        if (! $settings['enabled']) {
            return [];
        }

        $reason = "Automatic isolir: an invoice is more than {$settings['grace_days']} days past due.";
        $suspended = [];

        $subscriptions = ServiceSubscription::withoutCompany()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereIn('id', self::unpaidPastGrace($companyId, $settings['grace_days'])->select('subscription_id'))
            ->orderBy('id')
            ->get(['id', 'code']);

        foreach ($subscriptions as $subscription) {
            if (! $dryRun && ! self::suspendIfStillOverdue($companyId, $subscription, $reason, $settings['grace_days'])) {
                continue;
            }

            $suspended[] = ['id' => $subscription->id, 'code' => $subscription->code];
        }

        return $suspended;
    }

    /**
     * Reconnect a subscription that was isolated for non-payment once none of its invoices is past grace.
     * A manual suspension is never lifted here.
     */
    public static function reactivateIfCleared(int $companyId, int $subscriptionId): bool
    {
        $settings = self::settings($companyId);

        if (! $settings['reactivate']) {
            return false;
        }

        return DB::transaction(function () use ($companyId, $subscriptionId, $settings): bool {
            $subscription = ServiceSubscription::withoutCompany()
                ->where('company_id', $companyId)
                ->whereKey($subscriptionId)
                ->where('status', 'suspended')
                ->where('suspension_source', 'overdue')
                ->lockForUpdate()
                ->first();

            if ($subscription === null
                || self::unpaidPastGrace($companyId, $settings['grace_days'])->where('subscription_id', $subscriptionId)->exists()) {
                return false;
            }

            SubscriptionService::reactivate($subscription);

            return true;
        });
    }

    /**
     * The list above was read a while ago: a payment, a cancellation or a manual change may have landed since,
     * and a payment's own reconnect already ran against a still-active subscription. Under the subscription's
     * lock the invoices are read again with a locking read, which waits for a payment that is still in flight and
     * then sees what it committed.
     */
    private static function suspendIfStillOverdue(int $companyId, ServiceSubscription $subscription, string $reason, int $graceDays): bool
    {
        try {
            return DB::transaction(function () use ($companyId, $subscription, $reason, $graceDays): bool {
                $stillActive = ServiceSubscription::withoutCompany()
                    ->where('company_id', $companyId)
                    ->whereKey($subscription->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->value('id');

                $stillOverdue = $stillActive !== null && self::unpaidPastGrace($companyId, $graceDays)
                    ->where('subscription_id', $subscription->id)
                    ->lockForUpdate()
                    ->value('id') !== null;

                if (! $stillOverdue) {
                    return false;
                }

                SubscriptionService::suspend($subscription, $reason, 'overdue');

                return true;
            });
        } catch (HttpException) {
            return false; // someone else changed it in the meantime
        }
    }

    /** @return Builder<Invoice> */
    private static function unpaidPastGrace(int $companyId, int $graceDays): Builder
    {
        return Invoice::withoutCompany()
            ->where('company_id', $companyId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereColumn('paid_amount', '<', 'total')
            ->whereDate('due_date', '<', now()->subDays($graceDays)->toDateString());
    }

    /** @return array{enabled: bool, grace_days: int, reactivate: bool} */
    private static function settings(int $companyId): array
    {
        $settings = Company::find($companyId)->settings ?? [];

        return [
            'enabled' => filter_var($settings['auto_suspend_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'grace_days' => max(1, (int) ($settings['auto_suspend_overdue_days'] ?? self::DEFAULT_GRACE_DAYS)),
            'reactivate' => filter_var($settings['auto_reactivate_on_payment'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }
}
