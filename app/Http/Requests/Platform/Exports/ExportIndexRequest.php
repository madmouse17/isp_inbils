<?php

namespace App\Http\Requests\Platform\Exports;

use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'company_id' => [
                'nullable',
                'integer',
                Rule::exists((new Company())->getTable(), 'id')->whereNull('parent_company_id'),
            ],
            'status' => ['nullable', Rule::in(TenantExport::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ];
    }

    /** @return array{company_id: ?int, status: ?string, page: int, per_page: int} */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'company_id' => isset($validated['company_id']) ? (int) $validated['company_id'] : null,
            'status' => $validated['status'] ?? null,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 10),
        ];
    }
}
