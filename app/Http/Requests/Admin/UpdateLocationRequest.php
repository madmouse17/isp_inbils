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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        $location = $this->route('location');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('locations', 'code')->where('company_id', $companyId)->ignore($location?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['region', 'area', 'pop', 'rack', 'site'])],
            'parent_id' => ['nullable', 'required_unless:type,region', 'prohibited_if:type,region', Rule::exists('locations', 'id')->where('company_id', $companyId), Rule::notIn([$location instanceof Location ? $location->id : null])],
            'address' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
        ];
    }
}
