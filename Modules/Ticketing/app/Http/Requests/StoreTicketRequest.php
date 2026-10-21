<?php

namespace Modules\Ticketing\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Ticketing\Models\TicketCategory;

class StoreTicketRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return [
            'category_id' => TicketCategory::class,
            'customer_id' => Customer::class,
            'subscription_id' => ServiceSubscription::class,
            'network_asset_id' => NetworkAsset::class,
            'location_id' => Location::class,
        ];
    }

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
            /** @var string */
            'category_id' => ['required', Rule::exists('ticket_categories', 'id')->where('company_id', $companyId)],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            /** @var string|null */
            'customer_id' => array_filter([
                'nullable',
                Rule::exists('customers', 'id')->where('company_id', $companyId),
                $this->isCustomerUser() ? Rule::in([$this->ownCustomerId()]) : null,
            ]),
            /** @var string|null */
            'subscription_id' => ['nullable', Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)],
            /** @var string|null */
            'network_asset_id' => ['nullable', Rule::exists('network_assets', 'id')->where('company_id', $companyId)],
            // Portal customers never pick a location (not validated => not in validated()); see ticketAttributes().
            ...($this->isCustomerUser() ? [] : [
                /** @var string|null */
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
            $this->getValidatorInstance()->errors()->add('customer_id', 'Pelanggan wajib diisi jika sumber tiket adalah pelanggan.');
        }
    }

    private function isCustomerUser(): bool
    {
        return $this->user()?->can('portal.access') ?? false;
    }

    private function ownCustomerId(): ?int
    {
        return $this->user()?->customer?->id;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'source' => 'sumber',
        ];
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
                        $validator->errors()->add('subscription_id', 'Langganan bukan milik pelanggan yang dipilih.');
                    }
                }

                if ($this->isCustomerUser() && $networkAssetId) {
                    $asset = NetworkAsset::query()
                        ->where('company_id', $companyId)
                        ->find($networkAssetId);

                    if ($asset && (int) $asset->customer_id !== $this->ownCustomerId()) {
                        $validator->errors()->add('network_asset_id', 'Aset jaringan bukan milik pelanggan yang dipilih.');
                    }
                }

                if ($customerId && $networkAssetId) {
                    $asset = NetworkAsset::query()
                        ->where('company_id', $companyId)
                        ->find($networkAssetId);

                    if ($asset && $asset->customer_id !== null && (int) $asset->customer_id !== (int) $customerId) {
                        $validator->errors()->add('network_asset_id', 'Aset jaringan bukan milik pelanggan yang dipilih.');
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
                        $validator->errors()->add('network_asset_id', 'Aset jaringan bukan milik langganan yang dipilih.');
                    }
                }

                if ($locationId) {
                    $location = Location::query()
                        ->where('company_id', $companyId)
                        ->find($locationId);

                    if (! $location) {
                        $validator->errors()->add('location_id', 'Lokasi yang dipilih tidak valid untuk perusahaan ini.');
                    }
                }
            },
        ];
    }
}
