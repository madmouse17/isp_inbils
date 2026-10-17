<?php

namespace App\Http\Requests\Platform\Tenants;

use App\Queries\Platform\PlatformTenantListQuery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(PlatformTenantListQuery::STATUSES)],
            'sort' => ['nullable', Rule::in(PlatformTenantListQuery::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ];
    }

    /**
     * The filters with their defaults, in the shape the list query and the page both use.
     *
     * @return array{search: ?string, status: ?string, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'search' => isset($validated['search']) ? trim($validated['search']) ?: null : null,
            'status' => $validated['status'] ?? null,
            'sort' => $validated['sort'] ?? 'name',
            'direction' => $validated['direction'] ?? 'asc',
            'per_page' => (int) ($validated['per_page'] ?? 10),
        ];
    }
}
