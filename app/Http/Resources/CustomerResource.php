<?php

namespace App\Http\Resources;

use App\Models\Core\Customer;
use App\Services\WhatsApp\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'user_id' => $this->user?->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_id' => $this->tax_id,
            'contact_person' => $this->contact_person,
            'area_coverage_id' => $this->areaCoverage?->public_id,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'customer_status' => $this->customer_status,
            'addresses_count' => $this->whenCounted('addresses'),
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'addresses' => CustomerAddressResource::collection($this->whenLoaded('addresses')),
            'contacts' => CustomerContactResource::collection($this->whenLoaded('contacts')),
            'subscriptions' => SubscriptionResource::collection($this->whenLoaded('subscriptions')),
            'created_at' => $this->created_at,
            // Consent and reachability only; the number itself is already the `phone` above.
            'whatsapp' => [
                'opted_in' => $this->hasWhatsAppConsent(),
                'opted_in_at' => $this->whatsapp_opt_in_at,
                'opted_out_at' => $this->whatsapp_opt_out_at,
                'reachable' => PhoneNumber::normalizeIndonesianMobile($this->phone) !== null,
            ],
            // Only the customer pages load the relation, and only for holders of customer.sales.assign or
            // customer.sales.correct; other resources embedding a customer never carry attribution.
            $this->mergeWhen($this->relationLoaded('acquirer') && ($request->user()?->can('viewAcquirer', Customer::class) ?? false), fn (): array => [
                'acquired_by_user_id' => $this->acquirer?->public_id,
                'acquirer' => $this->acquirer === null ? null : [
                    'id' => $this->acquirer->public_id,
                    'name' => $this->acquirer->name,
                    'is_active' => (bool) $this->acquirer->is_active,
                ],
            ]),
        ];
    }
}
