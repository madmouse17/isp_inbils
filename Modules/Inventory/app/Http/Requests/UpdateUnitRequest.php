<?php

namespace Modules\Inventory\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $unitId = $this->route('unit')->id;
        $companyId = CompanyService::currentId();

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('units')->where('company_id', $companyId)->ignore($unitId)],
            'symbol' => ['required', 'string', 'max:20', Rule::unique('units')->where('company_id', $companyId)->ignore($unitId)],
        ];
    }
}
