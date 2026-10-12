<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NetworkAccountService;
use Modules\NetworkAsset\Services\Radius\Realm;

/** `username` is the local part; the company realm is appended when the account is saved. */
class StoreNetworkAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.account.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_type' => ['required', Rule::in(['pppoe', 'hotspot'])],
            'username' => ['bail', 'required', 'string', 'regex:'.NetworkAccountService::LOCAL_PART_PATTERN, $this->usernameIsFree(...)],
            // Blank = a 12-character secret is generated and shown once.
            'password' => ['nullable', 'string', 'min:6', 'max:64', 'regex:/^\S+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.regex' => 'Use 3 to 40 lower-case letters, digits, dots, dashes or underscores.',
            'password.regex' => 'The secret cannot contain spaces.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var ServiceSubscription $subscription */
            $subscription = $this->route('subscription');

            if (Realm::forCompany((int) CompanyService::currentId()) === null) {
                $validator->errors()->add('realm', 'The company code cannot be used as a RADIUS realm: use 1 to 24 letters, digits or dashes.');
            } elseif (NetworkAccount::query()->where('subscription_id', $subscription->id)->exists()) {
                $validator->errors()->add('subscription', 'This subscription already has a network account.');
            } elseif ($subscription->status === 'terminated') {
                $validator->errors()->add('subscription', 'A terminated subscription cannot have a network account.');
            } elseif (blank($subscription->servicePackage?->speedProfile?->getAttribute('radius_profile_name'))) {
                $validator->errors()->add('subscription', 'The speed profile of this package has no RADIUS profile name.');
            }
        }];
    }

    /** Global, across companies, and never says who holds a name. */
    private function usernameIsFree(string $attribute, mixed $value, \Closure $fail): void
    {
        if (NetworkAccountService::usernameTaken(NetworkAccountService::fullUsername((string) $value, (int) CompanyService::currentId()))) {
            $fail('This username is not available.');
        }
    }
}
