<?php

namespace App\Actions;

use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * The staff writer of customers.whatsapp_opt_* (the columns are not mass assignable; the only other writer
 * is the STOP reply handled by the delivery webhook). Ticking records when, from where and by whom the
 * customer agreed, and clears a withdrawal; unticking withdraws. The caller has already authorised the
 * actor (customer.create or customer.update). Resubmitting the current state writes and audits nothing.
 * Audit rows carry ids only, never the phone number.
 */
class SetCustomerWhatsAppConsentAction
{
    public static function execute(Customer $customer, bool $optIn, User $actor): void
    {
        if ($optIn === $customer->hasWhatsAppConsent()) {
            return;
        }

        DB::transaction(function () use ($customer, $optIn, $actor): void {
            $customer->forceFill($optIn
                ? [
                    'whatsapp_opt_in_at' => now(),
                    'whatsapp_opt_in_source' => 'staff',
                    'whatsapp_opt_in_by' => $actor->id,
                    'whatsapp_opt_out_at' => null,
                ]
                : ['whatsapp_opt_out_at' => now()])->save();

            AuditService::log('whatsapp', $optIn ? 'opted_in' : 'opted_out', [
                'customer_id' => $customer->id,
                'actor_id' => $actor->id,
                'source' => 'staff',
            ], $customer);
        });
    }
}
