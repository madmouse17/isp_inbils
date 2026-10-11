<?php

namespace Modules\Ipam\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ipam\Models\Vlan;

class StoreVlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Vlan::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vid' => ['required', 'integer', 'between:1,4094'],
            'name' => ['required', 'string', 'max:64'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', CompanyService::currentId())->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
