<?php

namespace Modules\Service\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSLATierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.create') || $this->user()?->can('service.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('sla_tiers')->where('company_id', CompanyService::currentId())],
            'response_time_hours' => ['required', 'integer', 'min:1', 'max:720', 'lte:resolution_time_hours'],
            'resolution_time_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'response_time_hours.lte' => 'Target respons tidak boleh lebih lama dari target penyelesaian.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'response_time_hours' => 'target respons',
            'resolution_time_hours' => 'target penyelesaian',
            'is_active' => 'status',
        ];
    }
}
