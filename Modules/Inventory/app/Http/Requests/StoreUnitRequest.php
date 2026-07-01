<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = \App\Services\Core\CompanyService::currentId();

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('units')->where('company_id', $companyId)],
            'symbol' => ['required', 'string', 'max:20', Rule::unique('units')->where('company_id', $companyId)],
        ];
    }
}
