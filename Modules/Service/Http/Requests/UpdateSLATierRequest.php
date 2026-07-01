<?php

namespace Modules\Service\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Service\Models\SLATier;

class UpdateSLATierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.update') || $this->user()?->can('service.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tier = $this->route('sla_tier');
        $id = $tier instanceof SLATier ? $tier->id : null;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('sla_tiers')->where('company_id', CompanyService::currentId())->ignore($id)],
            'uptime_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'response_time_hours' => ['required', 'integer', 'min:1'],
            'resolution_time_hours' => ['required', 'integer', 'min:1'],
            'credit_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
