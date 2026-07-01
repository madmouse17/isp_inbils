<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle.manage') ?? false;
    }

    public function rules(): array
    {
        $vehicleId = $this->route('vehicle')->id;
        $companyId = \App\Services\Core\CompanyService::currentId();
        return [
            'plate_number' => ['required', 'string', 'max:20', Rule::unique('vehicles')->where('company_id', $companyId)->ignore($vehicleId)],
            'type' => ['nullable', 'string', 'max:50'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
