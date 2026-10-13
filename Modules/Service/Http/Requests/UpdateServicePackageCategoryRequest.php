<?php

namespace Modules\Service\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The code never changes after creation: the pickers find the Rumahan, Bisnis and Korporat categories by it. */
class UpdateServicePackageCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.update') || $this->user()?->can('service.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'sort_order' => 'urutan',
            'is_active' => 'status',
        ];
    }
}
