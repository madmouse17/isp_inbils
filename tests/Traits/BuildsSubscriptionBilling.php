<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use Illuminate\Support\Str;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\ServicePackage;

/** Subscriptions with their own customer and invoices, built for one explicit company. */
trait BuildsSubscriptionBilling
{
    protected function subscriptionIn(Company $company, string $status = 'active', ?string $suspensionSource = null, ?ServicePackage $package = null): ServiceSubscription
    {
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'service_package_id' => ($package ?? ServicePackageFactory::new()->create(['company_id' => $company->id]))->id,
            'installation_address_id' => CustomerAddress::factory()->create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'label' => Str::random(12),
            ])->id,
            'status' => $status,
            'activation_date' => $status === 'pending' ? null : now()->subMonths(3)->toDateString(),
        ]);

        if ($suspensionSource !== null) {
            $subscription->forceFill(['suspension_source' => $suspensionSource])->save();
        }

        return $subscription;
    }

    /** @param array<string, mixed> $attributes */
    protected function invoiceFor(ServiceSubscription $subscription, int $daysPastDue, array $attributes = []): Invoice
    {
        return InvoiceFactory::new()->create(array_merge([
            'company_id' => $subscription->company_id,
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
            'due_date' => now()->subDays($daysPastDue)->toDateString(),
        ], $attributes));
    }

    protected function freshSubscription(ServiceSubscription $subscription): ServiceSubscription
    {
        return ServiceSubscription::withoutCompany()->findOrFail($subscription->id);
    }
}
