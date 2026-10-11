<?php

namespace App\Http\Resources;

use App\Models\Core\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_id' => $this->tax_id,
            'contact_person' => $this->contact_person,
            'area_coverage_id' => $this->area_coverage_id,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'addresses_count' => $this->whenCounted('addresses'),
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'addresses' => CustomerAddressResource::collection($this->whenLoaded('addresses')),
            'contacts' => CustomerContactResource::collection($this->whenLoaded('contacts')),
            'subscriptions' => SubscriptionResource::collection($this->whenLoaded('subscriptions')),
            'created_at' => $this->created_at,
            // Only the customer pages load the relation, and only for holders of customer.sales.assign or
            // customer.sales.correct; other resources embedding a customer never carry attribution.
            $this->mergeWhen($this->relationLoaded('acquirer') && ($request->user()?->can('viewAcquirer', Customer::class) ?? false), fn (): array => [
                'acquired_by_user_id' => $this->acquired_by_user_id,
                'acquirer' => $this->acquirer === null ? null : [
                    'id' => $this->acquirer->id,
                    'name' => $this->acquirer->name,
                    'is_active' => (bool) $this->acquirer->is_active,
                ],
            ]),
        ];
    }
}
