<?php

namespace Modules\Ticketing\Http\Requests;

use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\NetworkAsset;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isCustomerUser() && $this->ownCustomerId() === null) {
            return false;
        }

        return $this->user()?->can('ticket.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'source' => ['required', 'string', 'in:customer,noc,internal'],
            'category_id' => ['required', Rule::exists('ticket_categories', 'id')->where('company_id', $companyId)],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'customer_id' => array_filter([
                'nullable',
                Rule::exists('customers', 'id')->where('company_id', $companyId),
                $this->isCustomerUser() ? Rule::in([$this->ownCustomerId()]) : null,
            ]),
            'subscription_id' => ['nullable', Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)],
            'network_asset_id' => ['nullable', Rule::exists('network_assets', 'id')->where('company_id', $companyId)],
            // Portal customers never pick a location (not validated => not in validated()); see ticketAttributes().
            ...($this->isCustomerUser() ? [] : [
                'location_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            ]),
        ];
    }

    /**
     * Validated ticket data; customer tickets take their location from the subscription's serving location.
     *
     * @return array<string, mixed>
     */
    public function ticketAttributes(): array
    {
        $data = $this->validated();

        if ($this->isCustomerUser()) {
            $data['location_id'] = ServiceSubscription::query()
                ->whereKey($data['subscription_id'] ?? null)
                ->value('serving_pop_id');
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        if ($this->isCustomerUser() && ! $this->input('customer_id')) {
            $this->merge(['customer_id' => $this->ownCustomerId()]);
        }

        if ($this->input('source') === 'customer' && ! $this->input('customer_id')) {
            $this->getValidatorInstance()->errors()->add('customer_id', 'Customer required when source is customer.');
        }
    }

    private function isCustomerUser(): bool
    {
        return $this->user()?->hasRole('customer') ?? false;
    }

    private function ownCustomerId(): ?int
    {
        return $this->user()?->customer?->id;
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $companyId = CompanyService::currentId();
                $customerId = $this->input('customer_id');
                $subscriptionId = $this->input('subscription_id');
                $networkAssetId = $this->input('network_asset_id');
                $locationId = $this->isCustomerUser() ? null : $this->input('location_id');

                if ($customerId && $subscriptionId) {
                    $subscription = ServiceSubscription::query()
                        ->where('company_id', $companyId)
                        ->find($subscriptionId);

                    if ($subscription && (int) $subscription->customer_id !== (int) $customerId) {
                        $validator->errors()->add('subscription_id', 'Subscription does not belong to customer.');
                    }
                }

                if ($this->isCustomerUser() && $networkAssetId) {
                    $asset = NetworkAsset::query()
                        ->where('company_id', $companyId)
                        ->find($networkAssetId);

                    if ($asset && (int) $asset->customer_id !== $this->ownCustomerId()) {
                        $validator->errors()->add('network_asset_id', 'Network asset does not belong to customer.');
                    }
                }

                if ($customerId && $networkAssetId) {
                    $asset = NetworkAsset::query()
                        ->where('company_id', $companyId)
                        ->find($networkAssetId);

                    if ($asset && $asset->customer_id !== null && (int) $asset->customer_id !== (int) $customerId) {
                        $validator->errors()->add('network_asset_id', 'Network asset does not belong to customer.');
                    }
                }

                if ($subscriptionId && $networkAssetId) {
                    $subscription = ServiceSubscription::query()
                        ->where('company_id', $companyId)
                        ->find($subscriptionId);
                    $asset = NetworkAsset::query()
                        ->where('company_id', $companyId)
                        ->find($networkAssetId);

                    if ($subscription && $asset && $asset->subscription_id !== null && (int) $asset->subscription_id !== (int) $subscription->id) {
                        $validator->errors()->add('network_asset_id', 'Network asset does not belong to subscription.');
                    }
                }

                if ($locationId) {
                    $location = Location::query()
                        ->where('company_id', $companyId)
                        ->find($locationId);

                    if (! $location) {
                        $validator->errors()->add('location_id', 'Selected location is invalid for this company.');
                    }
                }
            },
        ];
    }
}
