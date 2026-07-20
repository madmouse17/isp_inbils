<?php

namespace App\Services\Core;

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
            $package = ServicePackage::findOrFail($data['service_package_id']);

            $data['mrc_amount'] ??= $package->price_mrc;
            $data['code'] = self::generateCode();
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
        abort_if($subscription->status !== 'pending', 422, 'Subscription must be pending to activate.');

        return DB::transaction(function () use ($subscription) {
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
        abort_if($subscription->status !== 'active', 422, 'Subscription must be active to suspend.');

        return DB::transaction(function () use ($subscription, $reason) {
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
        abort_if($subscription->status !== 'suspended', 422, 'Subscription must be suspended to reactivate.');

        return DB::transaction(function () use ($subscription) {
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

                abort_unless($asset, 422, 'ONT asset is unavailable.');
                abort_unless($asset->subscription_id === $subscription->id, 422, 'ONT asset must belong to the subscription.');
                abort_unless($asset->company_id === $subscription->customer?->company_id, 422, 'ONT asset must belong to the subscription company.');

                NetworkAssetService::remove($asset, 'subscription terminated: '.$reason);
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

    private static function generateCode(): string
    {
        $companyId = CompanyService::currentId();
        $year = now()->year;
        $prefix = "SUB-{$year}-";

        $last = ServiceSubscription::forCompany($companyId)
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->lockForUpdate()
            ->first();

        $next = $last ? ((int) substr($last->code, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private static function nextBillingDate(int $billingDay): Carbon
    {
        $today = now();
        $day = min($billingDay, $today->daysInMonth);

        if ($today->day < $billingDay) {
            return $today->setDay($day)->startOfDay();
        }

        return $today->addMonth()->setDay(min($billingDay, $today->addMonth()->daysInMonth))->startOfDay();
    }
}
