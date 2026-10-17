<?php

namespace Modules\SPK\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['customer_id' => Customer::class, 'subscription_id' => ServiceSubscription::class, 'location_id' => Location::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('spk.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'type' => ['required', 'string', 'in:installation,maintenance,upgrade_service,relocation'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'subscription_id' => ['nullable', 'integer', Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)->withoutTrashed()],
            'location_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'source' => ['nullable', 'string', 'in:manual,ticket,subscription,monitoring'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
        ];
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
                $customerId = $this->input('customer_id');
                $subscriptionId = $this->input('subscription_id');

                if (! $customerId || ! $subscriptionId) {
                    if (in_array($this->input('type'), ['installation', 'upgrade_service', 'relocation'], true)) {
                        $validator->errors()->add('customer_id', 'Pelanggan dan langganan wajib diisi untuk jenis SPK ini.');
                    }

                    return;
                }

                if ($validator->errors()->hasAny(['customer_id', 'subscription_id'])) {
                    return;
                }

                // Whatever the type, the invoice made from the SPK bills its customer: the subscription must be theirs.
                $subscription = ServiceSubscription::query()
                    ->where('company_id', CompanyService::currentId())
                    ->find($subscriptionId);
                if ($subscription && (int) $subscription->customer_id !== (int) $customerId) {
                    $validator->errors()->add('subscription_id', 'Langganan bukan milik pelanggan yang dipilih.');
                }
            },
        ];
    }
}
