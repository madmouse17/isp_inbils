<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('location.update') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('parent_id') && blank($this->input('parent_id'))) {
            $this->merge(['parent_id' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        $location = $this->route('location');

        return [
            // ">" builds the " > " path separator; a code containing it would fake ancestry in the path string.
            'code' => ['required', 'string', 'max:50', 'not_regex:/>/', Rule::unique('locations', 'code')->where('company_id', $companyId)->ignore($location?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['region', 'area', 'pop', 'rack', 'site'])],
            'parent_id' => [
                'nullable',
                Rule::requiredIf(function () use ($location): bool {
                    return $this->input('type') !== 'region'
                        && blank($this->input('parent_id'))
                        && blank($location?->parent_id);
                }),
                Rule::exists('locations', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
                Rule::notIn([$location instanceof Location ? $location->id : null]),
                function (string $attribute, mixed $value, \Closure $fail) use ($location): void {
                    if ($this->input('type') === 'region') {
                        if (! blank($value)) {
                            $fail('Region location cannot have parent.');
                        }

                        return;
                    }

                    $resolvedParentId = blank($value) ? $location?->parent_id : $value;

                    if (blank($resolvedParentId)) {
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
