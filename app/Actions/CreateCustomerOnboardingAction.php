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
use Modules\SPK\Models\WorkOrder;

class CreateCustomerOnboardingAction
{
    /** @param array<string, mixed> $data */
    public static function execute(array $data, User $creator): Customer
    {
        return DB::transaction(function () use ($data, $creator): Customer {
            $subscriptionData = Arr::pull($data, 'subscription');
            $acquirerInput = Arr::only($data, ['acquired_by_user_id']);
            unset($data['acquired_by_user_id']);

            $customer = CustomerService::createWithUser($data);
            AssignCustomerAcquirerAction::onCreate($customer, $creator, $acquirerInput);
            $installationAddress = $customer->addresses()->where('is_installation_point', true)->firstOrFail();
            $subscription = SubscriptionService::create([
                ...$subscriptionData,
                'customer_id' => $customer->id,
                'installation_address_id' => $installationAddress->id,
            ]);

            $workOrder = WorkOrder::query()->create([
                'code' => NumberSequenceService::generate('spk', 'SPK', CompanyService::currentId()),
                'type' => 'installation',
                'title' => 'Installation '.$subscription->code.' - '.$customer->name,
                'description' => 'Automatic installation SPK from customer onboarding.',
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
