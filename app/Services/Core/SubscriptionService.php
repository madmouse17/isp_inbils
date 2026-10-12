<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NetworkAssetService;
use Modules\NetworkAsset\Services\OdnService;
use Modules\Service\Models\ServicePackage;

class SubscriptionService
{
    public static function create(array $data): ServiceSubscription
    {
        return DB::transaction(function () use ($data) {
            $companyId = CompanyService::currentId();
            abort_if($companyId === null, 403, 'Company context is required.');

            $customer = Customer::query()->forCompany($companyId)->findOrFail($data['customer_id'] ?? null);
            /** @var ServicePackage $package */
            $package = ServicePackage::query()->forCompany($companyId)->findOrFail($data['service_package_id'] ?? null);

            if (! empty($data['installation_address_id'])) {
                CustomerAddress::forCompany($companyId)
                    ->where('customer_id', $customer->id)
                    ->findOrFail($data['installation_address_id']);
            }

            $data['mrc_amount'] ??= $package->getAttribute('price_mrc');
            $data['otc_installation_fee'] ??= $package->getAttribute('price_otc');
            if (empty($data['contract_months']) && (int) $package->getAttribute('contract_min_months') > 0) {
                $data['contract_months'] = $package->getAttribute('contract_min_months');
            }
            $data['code'] = NumberSequenceService::generate('subscription', 'SUB', $companyId);
            $data['status'] = $data['status'] ?? 'pending';

            $subscription = ServiceSubscription::create($data);

            AuditService::log('service_subscription', 'created', [
                'code' => $subscription->code,
                'customer_id' => $subscription->customer_id,
                'service_package_id' => $subscription->service_package_id,
            ], $subscription);

            CustomerStatusRecalculator::recalculate($subscription->customer_id);

            return $subscription->fresh();
        });
    }

    public static function activate(ServiceSubscription $subscription): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status !== 'pending', 422, 'Subscription must be pending to activate.');

            $subscription->update([
                'status' => 'active',
                'activation_date' => now(),
                'next_invoice_date' => self::nextBillingDate($subscription->billing_day),
            ]);

            AuditService::log('service_subscription', 'activated', [
                'code' => $subscription->code,
            ], $subscription);

            CustomerStatusRecalculator::recalculate($subscription->customer_id);

            return $subscription->fresh();
        });
    }

    public static function suspend(ServiceSubscription $subscription, string $reason, string $source = 'manual'): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription, $reason, $source) {
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status !== 'active', 422, 'Subscription must be active to suspend.');

            $subscription->forceFill([
                'status' => 'suspended',
                'suspension_source' => $source,
                'suspended_at' => $source === 'overdue' ? now() : null,
            ])->save();

            AuditService::log('service_subscription', 'suspended', [
                'code' => $subscription->code,
                'reason' => $reason,
                'source' => $source,
            ], $subscription);

            CustomerStatusRecalculator::recalculate($subscription->customer_id);

            return $subscription->fresh();
        });
    }

    public static function reactivate(ServiceSubscription $subscription): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status !== 'suspended', 422, 'Subscription must be suspended to reactivate.');

            $subscription->forceFill([
                'status' => 'active',
                'suspension_source' => null,
                'suspended_at' => null,
                'next_invoice_date' => self::nextBillingDate($subscription->billing_day),
            ])->save();

            AuditService::log('service_subscription', 'reactivated', [
                'code' => $subscription->code,
            ], $subscription);

            CustomerStatusRecalculator::recalculate($subscription->customer_id);

            return $subscription->fresh();
        });
    }

    public static function block(ServiceSubscription $subscription, string $reason): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status === 'terminated', 422, 'Terminated subscription cannot be blocked.');
            abort_if($subscription->blocked_at !== null, 422, 'Subscription is already blocked.');

            $subscription->forceFill([
                'blocked_at' => now(),
                'blocked_by' => auth()->id(),
                'block_reason' => $reason,
            ])->save();

            AuditService::log('service_subscription', 'blocked', [
                'code' => $subscription->code,
                'reason' => $reason,
            ], $subscription);

            return $subscription->fresh();
        });
    }

    public static function unblock(ServiceSubscription $subscription, ?string $note = null): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription, $note) {
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->blocked_at === null, 422, 'Subscription is not blocked.');

            $subscription->forceFill([
                'blocked_at' => null,
                'blocked_by' => null,
                'block_reason' => null,
            ])->save();

            AuditService::log('service_subscription', 'unblocked', [
                'code' => $subscription->code,
                'note' => $note,
            ], $subscription);

            return $subscription->fresh();
        });
    }

    public static function terminate(ServiceSubscription $subscription, string $reason, bool $releaseOnt = false): ServiceSubscription
    {
        abort_if($subscription->status === 'terminated', 422, 'Subscription already terminated.');

        return DB::transaction(function () use ($subscription, $reason, $releaseOnt) {
            $subscription = ServiceSubscription::with('customer')
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if ($releaseOnt && $subscription->ont_asset_id) {
                $asset = NetworkAsset::withoutCompany()
                    ->lockForUpdate()
                    ->find($subscription->ont_asset_id);

                if (! $asset) {
                    abort(422, 'ONT asset is unavailable.');
                }

                if ($asset->subscription_id === null || $asset->subscription_id !== $subscription->id) {
                    abort(422, 'ONT asset must belong to the subscription.');
                }

                if ($asset->getAttribute('company_id') !== $subscription->customer?->getAttribute('company_id')) {
                    abort(422, 'ONT asset must belong to the subscription company.');
                }

                NetworkAssetService::releaseOnt($asset, 'subscription terminated: '.$reason);
            }

            // Q10a: a terminated subscription gives back its static address(es) and its drop port in this same
            // transaction. Suspend keeps them. IpAssignmentChanged is dispatched after commit.
            IpAssignmentService::releaseForSubscription($subscription);
            OdnService::releaseForSubscription($subscription);

            $subscription->forceFill([
                'status' => 'terminated',
                'suspension_source' => null,
                'suspended_at' => null,
                'terminated_at' => now(),
                'blocked_at' => null,
                'blocked_by' => null,
                'block_reason' => null,
                'terminated_reason' => $reason,
                'ont_asset_id' => $releaseOnt ? null : $subscription->ont_asset_id,
            ])->save();

            AuditService::log('service_subscription', 'terminated', [
                'code' => $subscription->code,
                'reason' => $reason,
            ], $subscription);

            CustomerStatusRecalculator::recalculate($subscription->customer_id);

            return $subscription->fresh();
        });
    }

    private static function nextBillingDate(int $billingDay): Carbon
    {
        $today = now();

        if ($today->day < $billingDay) {
            return $today->copy()->setDay(min($billingDay, $today->daysInMonth))->startOfDay();
        }

        $next = $today->copy()->startOfMonth()->addMonthNoOverflow();

        return $next->setDay(min($billingDay, $next->daysInMonth))->startOfDay();
    }
}
