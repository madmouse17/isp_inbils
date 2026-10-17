<?php

namespace App\Http\Requests\Platform\Audit;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditIndexRequest extends FormRequest
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
            'platform_admin_id' => ['nullable', 'integer', Rule::exists((new PlatformAdmin())->getTable(), 'id')],
            'event' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ];
    }

    /** @return array{company_id: ?int, platform_admin_id: ?int, event: ?string, date_from: ?string, date_to: ?string, search: ?string, page: int, per_page: int} */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'company_id' => isset($validated['company_id']) ? (int) $validated['company_id'] : null,
            'platform_admin_id' => isset($validated['platform_admin_id']) ? (int) $validated['platform_admin_id'] : null,
            'event' => isset($validated['event']) ? trim($validated['event']) ?: null : null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'search' => isset($validated['search']) ? trim($validated['search']) ?: null : null,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 10),
        ];
    }
}
