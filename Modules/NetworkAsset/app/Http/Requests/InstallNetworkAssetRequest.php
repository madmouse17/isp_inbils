<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InstallNetworkAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.install') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'location_id' => ['required', Rule::exists('locations', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'customer_id' => ['nullable', 'required_with:subscription_id', Rule::exists('customers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'subscription_id' => ['nullable', Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'spk_id' => ['nullable', Rule::exists('work_orders', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'location_id' => 'lokasi',
            'customer_id' => 'pelanggan',
            'subscription_id' => 'langganan',
            'spk_id' => 'SPK',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $companyId = CompanyService::currentId();
                $customerId = $this->input('customer_id');
                $subscriptionId = $this->input('subscription_id');
                $locationId = $this->input('location_id');
                $spkId = $this->input('spk_id');

                if ($customerId !== null && $subscriptionId !== null) {
                    $subscription = DB::table('service_subscriptions')
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->find($subscriptionId);

                    if (is_object($subscription) && (int) $subscription->customer_id !== (int) $customerId) {
                        $validator->errors()->add('subscription_id', 'Langganan bukan milik pelanggan ini.');
                    }
                }

                if ($spkId === null) {
                    return;
                }

                $workOrder = DB::table('work_orders')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->where('id', $spkId)
                    ->first();
                if (! $workOrder) {
                    return;
                }

                if ($customerId !== null && (int) $workOrder->customer_id !== (int) $customerId) {
                    $validator->errors()->add('customer_id', 'Pelanggan tidak sesuai dengan SPK yang dipilih.');
                }

                if ($subscriptionId !== null && (int) $workOrder->subscription_id !== (int) $subscriptionId) {
                    $validator->errors()->add('subscription_id', 'Langganan tidak sesuai dengan SPK yang dipilih.');
                }

                if ($locationId !== null && (int) $workOrder->location_id !== (int) $locationId) {
                    $validator->errors()->add('location_id', 'Lokasi tidak sesuai dengan SPK yang dipilih.');
                }
            },
        ];
    }
}
