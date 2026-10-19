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

/** A blank secret keeps the stored one. `username` is the local part, as on create. */
class UpdateNetworkAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.account.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_type' => ['sometimes', 'required', Rule::in(['pppoe', 'hotspot'])],
            'username' => ['sometimes', 'bail', 'required', 'string', 'regex:'.NetworkAccountService::LOCAL_PART_PATTERN, $this->usernameIsFree(...)],
            'password' => ['nullable', 'string', 'min:6', 'max:64', 'regex:/^\S+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.regex' => 'Gunakan 3 sampai 40 huruf kecil, angka, titik, tanda hubung, atau garis bawah.',
            'password.regex' => 'Kata sandi tidak boleh berisi spasi.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'service_type' => 'jenis layanan',
            'username' => 'nama pengguna',
            'password' => 'kata sandi',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $subscription = $this->subscription();

            if (Realm::forCompany((int) CompanyService::currentId()) === null) {
                $validator->errors()->add('realm', 'Kode perusahaan tidak dapat dipakai sebagai realm RADIUS: gunakan 1 sampai 24 huruf, angka, atau tanda hubung.');
            } elseif ($subscription->status === 'terminated') {
                $validator->errors()->add('subscription', 'Langganan yang sudah berhenti tidak dapat punya akun jaringan.');
            } elseif (blank($subscription->packageVersion?->speedProfile?->getAttribute('radius_profile_name'))) {
                $validator->errors()->add('subscription', 'Profil kecepatan paket ini belum punya nama profil RADIUS.');
            }
        }];
    }

    /** Global, across companies; the account being edited keeps its own name. */
    private function usernameIsFree(string $attribute, mixed $value, \Closure $fail): void
    {
        $accountId = NetworkAccount::query()->where('subscription_id', $this->subscription()->id)->value('id');

        if (NetworkAccountService::usernameTaken(NetworkAccountService::fullUsername((string) $value, (int) CompanyService::currentId()), $accountId)) {
            $fail('Nama pengguna ini tidak tersedia.');
        }
    }

    private function subscription(): ServiceSubscription
    {
        /** @var ServiceSubscription */
        return $this->route('subscription');
    }
}
