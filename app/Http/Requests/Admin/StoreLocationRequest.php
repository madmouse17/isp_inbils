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
            'code' => ['required', 'string', 'max:50', Rule::unique('locations', 'code')->where('company_id', $companyId)],
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
                            $fail('Region location cannot have parent.');
                        }

                        return;
                    }

                    if (blank($value)) {
                        $fail('Parent location is required for non-region location.');
                    }
                },
            ],
            'address' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->filled('lat') === $this->filled('lng')) {
                    return;
                }

                $fail('Latitude and longitude must be provided together.');
            }],
            'lng' => ['nullable', 'numeric', 'between:-180,180', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->filled('lat') === $this->filled('lng')) {
                    return;
                }

                $fail('Latitude and longitude must be provided together.');
            }],
            'is_active' => ['boolean'],
        ];
    }
}
