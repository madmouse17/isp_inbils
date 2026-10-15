<?php

namespace App\Http\Requests\Admin;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('company.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('companies', 'code')->ignore(CompanyService::currentId())],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'mimetypes:image/jpeg,image/png,image/webp,image/svg+xml', 'max:10240'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9()+-]+$/'],
            'whatsapp' => ['nullable', 'string', 'max:50', 'regex:/^[0-9()+-]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_hours' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Telepon hanya boleh berisi angka dan karakter ( ) + -.',
            'whatsapp.regex' => 'Nomor WhatsApp hanya boleh berisi angka dan karakter ( ) + -.',
            'brand_color.regex' => 'Warna merek harus berupa kode warna heksadesimal seperti #2563EB.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'brand_color' => 'warna merek',
            'whatsapp' => 'nomor WhatsApp',
            'support_hours' => 'jam layanan',
        ];
    }
}
