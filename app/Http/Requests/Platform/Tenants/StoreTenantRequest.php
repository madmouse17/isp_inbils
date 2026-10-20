<?php

namespace App\Http\Requests\Platform\Tenants;

use Database\Seeders\CompanySeeder;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** "Perusahaan baru": the company, its logo, what it starts with and the first admin, who is invited to set a password. */
class StoreTenantRequest extends FormRequest
{
    public const CURRENCIES = ['IDR', 'USD', 'SGD', 'EUR'];

    /** Letters, digits, dash and underscore, starting with a letter or digit: safe in a URL, a file name and the typed confirmation. */
    public const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/D';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'regex:'.self::CODE_PATTERN, Rule::unique('companies', 'code')],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'mimetypes:image/jpeg,image/png,image/webp,image/svg+xml', 'max:10240'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'currency' => ['required', 'string', Rule::in(self::CURRENCIES)],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'demo_data' => ['nullable', 'boolean'],
            'catalog' => ['required', 'string', Rule::in([CompanySeeder::CATALOG_STARTER, CompanySeeder::CATALOG_EMPTY])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['admin_email' => 'email admin', 'demo_data' => 'data contoh', 'catalog' => 'isi awal'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // The sample story stands on the starter catalog's products, packages and POPs.
            if ($this->boolean('demo_data') && $this->input('catalog') === CompanySeeder::CATALOG_EMPTY) {
                $validator->errors()->add('catalog', 'Data contoh butuh Katalog awal.');
            }
        }];
    }
}
