<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Notifications\SubscriptionStatusNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NetworkAssetService;
use Modules\NetworkAsset\Services\OdnService;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageVersion;

class SubscriptionService
{
    public static function create(array $data): ServiceSubscription
    {
        return DB::transaction(function () use ($data) {
            $companyId = CompanyService::currentId();
            abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

            $customer = Customer::query()->forCompany($companyId)->lockForUpdate()->findOrFail($data['customer_id'] ?? null);
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
            $data['code'] = NumberSequenceService::generate('subscription', $companyId);
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
            self::lockCustomer($subscription->customer_id);
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status !== 'pending', 422, 'Langganan harus berstatus Menunggu untuk diaktifkan.');

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
            self::lockCustomer($subscription->customer_id);
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status !== 'active', 422, 'Langganan harus berstatus Aktif untuk ditangguhkan.');

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

            // A block already isolated the customer, who was told then.
            if ($subscription->blocked_at === null) {
                self::tellCustomer($subscription, $source === 'overdue' ? 'overdue' : 'manual');
            }

            return $subscription->fresh();
        });
    }

    public static function reactivate(ServiceSubscription $subscription): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription) {
            self::lockCustomer($subscription->customer_id);
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status !== 'suspended', 422, 'Langganan harus berstatus Ditangguhkan untuk diaktifkan kembali.');

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

            // A block keeps the customer isolated; they are told when it is lifted.
            if ($subscription->blocked_at === null) {
                self::tellCustomer($subscription, null);
            }

            return $subscription->fresh();
        });
    }

    public static function block(ServiceSubscription $subscription, string $reason): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->status === 'terminated', 422, 'Langganan yang sudah berhenti tidak bisa diblokir.');
            abort_if($subscription->blocked_at !== null, 422, 'Internet langganan sudah diblokir.');

            $subscription->forceFill([
                'blocked_at' => now(),
                'blocked_by' => auth()->id(),
                'block_reason' => $reason,
            ])->save();

            AuditService::log('service_subscription', 'blocked', [
                'code' => $subscription->code,
                'reason' => $reason,
            ], $subscription);

            // Only an active subscription is served: for any other the customer sees no change.
            if ($subscription->status === 'active') {
                self::tellCustomer($subscription, 'blocked');
            }

            return $subscription->fresh();
        });
    }

    public static function unblock(ServiceSubscription $subscription, ?string $note = null): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription, $note) {
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->blocked_at === null, 422, 'Internet langganan tidak sedang diblokir.');

            $subscription->forceFill([
                'blocked_at' => null,
                'blocked_by' => null,
                'block_reason' => null,
            ])->save();

            AuditService::log('service_subscription', 'unblocked', [
                'code' => $subscription->code,
                'note' => $note,
            ], $subscription);

            // A suspended subscription stays isolated until it is reactivated.
            if ($subscription->status === 'active') {
                self::tellCustomer($subscription, null);
            }

            return $subscription->fresh();
        });
    }

    /**
     * Moves a subscription to the latest version of a package, its own or another one (spec .scratch/master-lifecycle D3).
     * Editing a package moves nobody: this is the one explicit, audited step. The monthly fee becomes $mrcAmount, or the
     * price of that version when none is given; the installation fee follows the version only while the subscription
     * still waits for its installation. Nothing is prorated, the next invoice bills the new monthly fee. The network
     * observer queues the RADIUS re-apply once this transaction commits.
     */
    public static function changePackage(ServiceSubscription $subscription, int $packageId, string|int|float|null $mrcAmount = null): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription, $packageId, $mrcAmount) {
            self::lockCustomer($subscription->customer_id);
            $subscription = ServiceSubscription::lockForUpdate()->findOrFail($subscription->id);

            if ($subscription->status === 'terminated') {
                self::refuse('Langganan yang sudah berhenti tidak bisa pindah paket.');
            }

            // Locked before the versions are read: a package edit that is still writing its next version finishes first.
            $package = ServicePackage::query()->forCompany((int) $subscription->company_id)->lockForUpdate()->findOrFail($packageId);
            $latest = ServicePackageVersion::withoutCompany()->where('service_package_id', $package->id)->orderByDesc('version')->firstOrFail();

            if ((int) $latest->id === (int) $subscription->service_package_version_id) {
                self::refuse('Langganan sudah memakai versi terbaru paket ini.');
            }

            $subscription->loadMissing('servicePackage:id,code', 'packageVersion:id,version');
            $from = self::packageTerms($subscription->servicePackage->getAttribute('code'), $subscription->packageVersion->version, $subscription);

            $subscription->update([
                'service_package_id' => $package->id,
                'service_package_version_id' => $latest->id,
                'mrc_amount' => $mrcAmount ?? $latest->price_mrc,
                ...($subscription->status === 'pending' ? ['otc_installation_fee' => $latest->price_otc] : []),
            ]);

            AuditService::log('service_subscription', 'package_changed', [
                'code' => $subscription->code,
                'from' => $from,
                'to' => self::packageTerms($package->getAttribute('code'), $latest->version, $subscription),
            ], $subscription);

            return $subscription->fresh();
        });
    }

    public static function terminate(ServiceSubscription $subscription, string $reason, bool $releaseOnt = false): ServiceSubscription
    {
        return DB::transaction(function () use ($subscription, $reason, $releaseOnt) {
            self::lockCustomer($subscription->customer_id);

            $subscription = ServiceSubscription::with('customer')
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            // The caller's copy can be older than a terminate that committed meanwhile; only the locked row tells.
            abort_if($subscription->status === 'terminated', 422, 'Langganan sudah berhenti.');

            if ($releaseOnt && $subscription->ont_asset_id) {
                $asset = NetworkAsset::withoutCompany()
                    ->lockForUpdate()
                    ->find($subscription->ont_asset_id);

                if (! $asset) {
                    abort(422, 'Aset ONT tidak tersedia.');
                }

                if ($asset->subscription_id === null || $asset->subscription_id !== $subscription->id) {
                    abort(422, 'Aset ONT harus terpasang pada langganan ini.');
                }

                if ($asset->getAttribute('company_id') !== $subscription->customer?->getAttribute('company_id')) {
                    abort(422, 'Aset ONT harus milik perusahaan langganan ini.');
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

    /**
     * The next invoice date `activate` and `reactivate` set: this month's billing day while it is still ahead, else next
     * month's. Public so the subscription page can show it in the reactivate dialog before staff confirm (K27).
     */
    public static function nextBillingDate(int $billingDay): Carbon
    {
        $today = now();

        if ($today->day < $billingDay) {
            return $today->copy()->setDay(min($billingDay, $today->daysInMonth))->startOfDay();
        }

        $next = $today->copy()->startOfMonth()->addMonthNoOverflow();

        return $next->setDay(min($billingDay, $next->daysInMonth))->startOfDay();
    }

    /**
     * Lane lock order: the customer row before any of its subscriptions. Two transitions of one customer queue
     * here, at their first statement, and never wait on each other in opposite order.
     */
    private static function lockCustomer(int $customerId): void
    {
        Customer::query()->whereKey($customerId)->lockForUpdate()->value('id');
    }

    /**
     * The customer hears it, in the bell and on the phone when the app is installed, when the service goes from served to
     * isolated or back (`$isolatedBy` null). The row is written after the surrounding transaction committed and carries ids only.
     * A customer without a login in the app has no bell, so nothing is queued for them.
     *
     * @param  'overdue'|'manual'|'blocked'|null  $isolatedBy
     */
    private static function tellCustomer(ServiceSubscription $subscription, ?string $isolatedBy): void
    {
        Customer::query()->whereKey($subscription->customer_id)->whereNotNull('user_id')->first()
            ?->notify(new SubscriptionStatusNotification((int) $subscription->company_id, $subscription->id, $isolatedBy));
    }

    /** @return array{package: string, version: int, mrc_amount: string, otc_installation_fee: string} */
    private static function packageTerms(string $packageCode, int $version, ServiceSubscription $subscription): array
    {
        return [
            'package' => $packageCode,
            'version' => $version,
            'mrc_amount' => $subscription->mrc_amount,
            'otc_installation_fee' => $subscription->otc_installation_fee,
        ];
    }

    /** A refusal the Ganti paket form shows under its package field. */
    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['service_package_id' => $message]);
    }
}
