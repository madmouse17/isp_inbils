<?php

namespace App\Http\Requests\Admin;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('location.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            // ">" builds the " > " path separator; a code containing it would fake ancestry in the path string.
            'code' => ['required', 'string', 'max:50', 'not_regex:/>/', Rule::unique('locations', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['region', 'area', 'pop', 'rack', 'site'])],
            'parent_id' => [
                'nullable',
                Rule::exists('locations', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $type = $this->input('type');

                    if ($type === 'region') {
                        if (! blank($value)) {
                            $fail('Lokasi Wilayah tidak boleh punya induk.');
                        }

                        return;
                    }

                    if (blank($value)) {
                        $fail('Induk lokasi wajib diisi untuk lokasi selain Wilayah.');
                    }
                },
            ],
            'address' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->filled('lat') === $this->filled('lng')) {
                    return;
                }

                $fail('Lintang dan bujur harus diisi bersamaan.');
            }],
            'lng' => ['nullable', 'numeric', 'between:-180,180', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->filled('lat') === $this->filled('lng')) {
                    return;
                }

                $fail('Lintang dan bujur harus diisi bersamaan.');
            }],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.not_regex' => 'Kode tidak boleh memuat tanda ">".',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'kode',
            'name' => 'nama',
            'type' => 'tipe',
            'parent_id' => 'induk',
            'address' => 'alamat',
            'lat' => 'lintang',
            'lng' => 'bujur',
            'is_active' => 'status aktif',
        ];
    }
}
