<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of customers.customer_status (isp_ari ADR-011): rolls the customer's non-deleted
 * subscriptions up into one status.
 */
class CustomerStatusRecalculator
{
    /** Pure rule. No subscriptions, or only pending ones, means the customer is still a prospect. */
    public static function derive(array $subscriptionStatuses): string
    {
        $statuses = array_unique($subscriptionStatuses);

        return match (true) {
            in_array('active', $statuses, true) => 'active',
            in_array('suspended', $statuses, true) => 'suspended',
            array_diff($statuses, ['pending']) === [] => 'prospective',
            array_diff($statuses, ['terminated']) === [] => 'terminated',
            default => 'inactive',
        };
    }

    /** The status the customer should have right now; reads, never writes. */
    public static function expected(Customer $customer): string
    {
        return self::derive($customer->subscriptions()->pluck('status')->all());
    }

    /**
     * Recompute and persist one customer's status, auditing only a real change. The customer row is
     * locked before the subscriptions are read, so concurrent transitions of one customer serialize.
     */
    public static function recalculate(int $customerId): string
    {
        return DB::transaction(function () use ($customerId): string {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $from = $customer->customer_status;
            $to = self::expected($customer);

            if ($from !== $to) {
                $customer->forceFill(['customer_status' => $to])->save();
                AuditService::log('customer', 'status_changed', ['from' => $from, 'to' => $to], $customer);
            }

            return $to;
        });
    }
}
