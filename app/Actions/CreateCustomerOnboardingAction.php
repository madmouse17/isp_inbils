<?php

namespace App\Actions;

use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\CustomerService;
use App\Services\Core\NumberSequenceService;
use App\Services\Core\SubscriptionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Services\OdnService;
use Modules\SPK\Models\WorkOrder;

class CreateCustomerOnboardingAction
{
    /** @param array<string, mixed> $data */
    public static function execute(array $data, User $creator): Customer
    {
        return DB::transaction(function () use ($data, $creator): Customer {
            $subscriptionData = Arr::pull($data, 'subscription');
            $odnPortId = Arr::pull($subscriptionData, 'odn_port_id');
            $acquirerInput = Arr::only($data, ['acquired_by_user_id']);
            unset($data['acquired_by_user_id']);
            $whatsAppOptIn = filter_var(Arr::pull($data, 'whatsapp_opt_in', false), FILTER_VALIDATE_BOOLEAN);

            $data['code'] = NumberSequenceService::resolve('customer', $data['code'] ?? null);
            $customer = CustomerService::createWithUser($data);
            AssignCustomerAcquirerAction::onCreate($customer, $creator, $acquirerInput);

            if ($whatsAppOptIn) {
                SetCustomerWhatsAppConsentAction::execute($customer, true, $creator);
            }

            $installationAddress = $customer->addresses()->where('is_installation_point', true)->firstOrFail();
            $subscription = SubscriptionService::create([
                ...$subscriptionData,
                'customer_id' => $customer->id,
                'installation_address_id' => $installationAddress->id,
            ]);

            // Q9a: the optional drop port is reserved in this same transaction, so an occupied, foreign or
            // out-of-POP port refuses the whole onboarding and leaves no customer, subscription or SPK behind.
            if ($odnPortId !== null) {
                OdnService::assignDrop((int) $odnPortId, $subscription, 'subscription.odn_port_id');
            }

            $workOrder = WorkOrder::query()->create([
                'code' => NumberSequenceService::generate('spk', CompanyService::currentId()),
                'type' => 'installation',
                'title' => 'Pemasangan '.$subscription->code.' - '.$customer->name,
                'description' => 'SPK pemasangan otomatis dari penambahan pelanggan baru.',
                'status' => 'generated',
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'location_id' => $subscription->serving_pop_id,
                'source' => 'subscription',
                'priority' => 'medium',
                'created_by' => $creator->id,
            ]);

            AuditService::log('customer', 'onboarded', [
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'work_order_id' => $workOrder->id,
            ], $customer);

            return $customer;
        });
    }
}
