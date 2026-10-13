<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('company.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
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
}
