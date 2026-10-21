<?php

namespace Modules\SPK\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\SPK\Services\SpkService;

class UpdateWorkOrderRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['customer_id' => Customer::class, 'subscription_id' => ServiceSubscription::class, 'location_id' => Location::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('spk.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        // The visit day cannot be moved into the past (K16, as in the assign dialog). The day the SPK already has may stay:
        // the edit page sends it back untouched, and a plan whose day went by could not be saved at all.
        $keepsVisitDay = $this->input('scheduled_date') === $this->route('wo')?->scheduled_date?->toDateString();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'subscription_id' => ['nullable', 'integer', Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)->withoutTrashed()],
            'location_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'scheduled_date' => ['bail', 'nullable', 'date_format:Y-m-d', Rule::when(! $keepsVisitDay, ['after_or_equal:'.SpkService::earliestVisitDate()])],
            OptimisticLock::FIELD => OptimisticLock::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['scheduled_date.after_or_equal' => 'Tanggal kunjungan tidak boleh sebelum hari ini.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['scheduled_date' => 'tanggal kunjungan'];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['customer_id', 'subscription_id']) || $validator->errors()->hasAny(['customer_id', 'subscription_id'])) {
                    return;
                }

                // The pair is checked as it would be stored: a side the request leaves out stays what the SPK has now.
                $workOrder = $this->route('wo');
                $customerId = $this->has('customer_id') ? $this->input('customer_id') : $workOrder?->customer_id;
                $subscriptionId = $this->has('subscription_id') ? $this->input('subscription_id') : $workOrder?->subscription_id;

                if (! $customerId || ! $subscriptionId) {
                    return;
                }

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
