<?php

namespace Modules\Billing\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateFromSpkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'work_order_id' => ['required', Rule::exists('work_orders', 'id')->where('company_id', CompanyService::currentId())],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'work_order_id' => 'SPK',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'work_order_id.required' => 'SPK wajib dipilih.',
            'work_order_id.exists' => 'SPK yang dipilih tidak ditemukan.',
        ];
    }
}
