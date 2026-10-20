<?php

namespace App\Http\Requests\Admin;

use App\Http\Middleware\EnforceCompanyTwoFactor;
use App\Services\Core\CompanyService;
use App\Support\OptimisticLock;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanySettingsRequest extends FormRequest
{
    /** Stamped by the controller on the first save of the form (F1-U05); a client never sets or clears it. */
    public const BILLING_CONFIRMED_AT = 'billing_defaults_confirmed_at';

    public function authorize(): bool
    {
        return $this->user()?->can('company.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');

        if (is_array($settings) && array_key_exists(self::BILLING_CONFIRMED_AT, $settings)) {
            unset($settings[self::BILLING_CONFIRMED_AT]);
            $this->merge(['settings' => $settings]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
            'settings.auto_send_installation_invoice' => ['sometimes', 'required', 'boolean'],
            'settings.bill_installation_materials' => ['sometimes', 'required', 'boolean'],
            'settings.auto_suspend_enabled' => ['sometimes', 'required', 'boolean'],
            'settings.auto_suspend_overdue_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:60'],
            'settings.auto_reactivate_on_payment' => ['sometimes', 'required', 'boolean'],
            'settings.whatsapp_enabled' => ['sometimes', 'required', 'boolean'],
            'settings.whatsapp_events' => ['sometimes', 'array'],
            'settings.whatsapp_events.*' => ['string', Rule::in(array_keys(config('whatsapp.events')))],
            'settings.whatsapp_quiet_start' => ['sometimes', 'required', 'date_format:H:i'],
            'settings.whatsapp_quiet_end' => ['sometimes', 'required', 'date_format:H:i'],
            'settings.whatsapp_monthly_cap' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'settings.map_city_code' => ['sometimes', 'nullable', 'string', 'exists:indonesia_cities,code'],
            // Indonesia's bounding box; lat and lng are only meaningful as a pair.
            'settings.map_lat' => ['nullable', 'required_with:settings.map_lng', 'numeric', 'between:-11,6'],
            'settings.map_lng' => ['nullable', 'required_with:settings.map_lat', 'numeric', 'between:94,142'],
            'settings.map_zoom' => ['sometimes', 'nullable', 'integer', 'between:3,18'],
            'settings.'.EnforceCompanyTwoFactor::SETTING => ['bail', 'sometimes', 'required', 'boolean', $this->ownTwoFactorBeforeRequiring(...)],
            OptimisticLock::FIELD => OptimisticLock::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'settings.map_lat.between' => 'Lintang harus di antara -11 dan 6.',
            'settings.map_lng.between' => 'Bujur harus di antara 94 dan 142.',
            'settings.map_zoom.between' => 'Zoom harus di antara 3 dan 18.',
        ];
    }

    /** Turning the policy on locks out every admin who has no second factor yet, so whoever turns it on must hold one. */
    private function ownTwoFactorBeforeRequiring(string $attribute, mixed $value, Closure $fail): void
    {
        $turnsOn = filter_var($value, FILTER_VALIDATE_BOOLEAN) && ! EnforceCompanyTwoFactor::required(CompanyService::current());

        if ($turnsOn && ! $this->user()?->hasTwoFactorEnabled()) {
            $fail('Aktifkan 2FA di profil Anda dulu sebelum mewajibkannya.');
        }
    }
}
