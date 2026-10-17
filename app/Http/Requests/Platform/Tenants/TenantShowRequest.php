<?php

namespace App\Http\Requests\Platform\Tenants;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The query string of a tenant's detail page: only its user list is filterable. */
class TenantShowRequest extends FormRequest
{
    public const STATUSES = ['active', 'inactive'];

    public const PER_PAGE = [10, 25, 50];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', Rule::in(self::PER_PAGE)],
        ];
    }

    /**
     * The user-list filters with their defaults: the shape the query and the page's `userFilters` prop both use.
     *
     * @return array{search: ?string, status: ?string, per_page: int}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'search' => isset($validated['search']) ? trim($validated['search']) ?: null : null,
            'status' => $validated['status'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? self::PER_PAGE[0]),
        ];
    }
}
