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
        ];
    }
}
