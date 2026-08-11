<?php

namespace Modules\SPK\Http\Requests;

use App\Models\Core\ServiceSubscription;
use App\Rules\MatchesParentAttribute;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;

class StoreWorkOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('spk.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'network_asset_id' => [
                Rule::requiredIf(fn () => $this->productType($companyId) === 'asset'),
                Rule::prohibitedIf(fn () => $this->productType($companyId) !== 'asset'),
                Rule::exists('network_assets', 'id')->where('company_id', $companyId)->where('status', 'available'),
                new MatchesParentAttribute(NetworkAsset::class, 'product_id', 'product_id'),
            ],
            'quantity_reserved' => ['nullable', 'numeric', 'min:0'],
            'quantity_used' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $companyId = CompanyService::currentId();
                $networkAssetId = $this->input('network_asset_id');
                $workOrder = $this->route('work_order');
                $subscriptionId = $workOrder?->subscription_id;

                if (! $networkAssetId) {
                    return;
                }

                $asset = NetworkAsset::query()
                    ->where('company_id', $companyId)
                    ->find($networkAssetId);
                if ($asset && (int) $asset->company_id !== (int) $companyId) {
                    $validator->errors()->add('network_asset_id', 'Selected network asset is invalid for this company.');
                }

                if ($subscriptionId) {
                    $subscription = ServiceSubscription::query()
                        ->where('company_id', $companyId)
                        ->find($subscriptionId);
                    if ($subscription && (int) $subscription->company_id !== (int) $companyId) {
                        $validator->errors()->add('network_asset_id', 'Selected network asset is invalid for this company.');
                    }
                }

                $activeInstallation = NetworkAssetInstallation::query()
                    ->where('company_id', $companyId)
                    ->where('network_asset_id', $networkAssetId)
                    ->whereNull('removed_at')
                    ->exists();

                if ($activeInstallation) {
                    $validator->errors()->add('network_asset_id', 'Selected network asset already has an active installation.');
                }
            },
        ];
    }

    private function productType(int $companyId): ?string
    {
        return Product::query()
            ->where('company_id', $companyId)
            ->whereKey($this->input('product_id'))
            ->value('type');
    }
}
