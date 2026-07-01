<?php

namespace App\Http\Requests\Setup;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanySetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('companies', 'code')],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,svg', 'max:2048'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'currency' => ['required', 'string', Rule::in(['IDR', 'USD', 'SGD', 'EUR'])],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'date_format' => ['required', 'string', 'max:50'],
            'datetime_format' => ['required', 'string', 'max:50'],
            'admin_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
