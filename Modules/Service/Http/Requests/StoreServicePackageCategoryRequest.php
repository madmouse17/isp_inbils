<?php

namespace Modules\Service\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServicePackageCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.create') || $this->user()?->can('service.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('service_package_categories')->where('company_id', CompanyService::currentId())],
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Kode kategori sudah dipakai.',
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'kode',
            'name' => 'nama',
            'sort_order' => 'urutan',
            'is_active' => 'status',
        ];
    }
}
