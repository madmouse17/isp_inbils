<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\NetworkAccount;

/** A blank password keeps the stored one. */
class UpdateNetworkAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.account.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        $accountId = NetworkAccount::query()->where('subscription_id', $this->subscription()->id)->value('id');

        return [
            'nas_device_id' => ['sometimes', 'required', 'integer', Rule::exists('nas_devices', 'id')->where('company_id', $companyId)],
            'service_type' => ['sometimes', 'required', Rule::in(['pppoe', 'hotspot'])],
            'username' => [
                'sometimes',
                'required',
                'string',
                'regex:/^[A-Za-z0-9._@-]{3,64}$/',
                Rule::unique('network_accounts', 'username')->where('company_id', $companyId)->ignore($accountId),
            ],
            'password' => ['nullable', 'string', 'min:6', 'max:64', 'regex:/^\S+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nas_device_id.exists' => 'Choose a NAS router of this company.',
            'username.regex' => 'Use 3 to 64 letters, digits, dots, dashes, underscores or @.',
            'username.unique' => 'This username is already used by another network account.',
            'password.regex' => 'The password cannot contain spaces.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $subscription = $this->subscription();

            if ($subscription->status === 'terminated') {
                $validator->errors()->add('subscription', 'A terminated subscription cannot have a network account.');
            } elseif (blank($subscription->servicePackage?->speedProfile?->getAttribute('radius_profile_name'))) {
                $validator->errors()->add('subscription', 'The speed profile of this package has no router profile name.');
            }
        }];
    }

    private function subscription(): ServiceSubscription
    {
        /** @var ServiceSubscription */
        return $this->route('subscription');
    }
}
