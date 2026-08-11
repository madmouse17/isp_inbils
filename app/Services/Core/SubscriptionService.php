<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NetworkAssetService;
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

            return $subscription->fresh();
        });
    }

    public static function suspend(ServiceSubscription $subscription, string $reason): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status !== 'active', 422, 'Subscription must be active to suspend.');

            $subscription->update(['status' => 'suspended']);

            AuditService::log('service_subscription', 'suspended', [
                'code' => $subscription->code,
                'reason' => $reason,
            ], $subscription);

            return $subscription->fresh();
        });
    }

    public static function reactivate(ServiceSubscription $subscription): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status !== 'suspended', 422, 'Subscription must be suspended to reactivate.');

            $subscription->update([
                'status' => 'active',
                'next_invoice_date' => self::nextBillingDate($subscription->billing_day),
            ]);

            AuditService::log('service_subscription', 'reactivated', [
                'code' => $subscription->code,
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
            $subscription->update([
                'status' => 'terminated',
                'terminated_at' => now(),
                'terminated_reason' => $reason,
                'ont_asset_id' => $releaseOnt ? null : $subscription->ont_asset_id,
            ]);

            AuditService::log('service_subscription', 'terminated', [
                'code' => $subscription->code,
                'reason' => $reason,
            ], $subscription);

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
