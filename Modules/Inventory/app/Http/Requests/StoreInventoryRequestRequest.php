<?php

namespace Modules\Inventory\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryRequest;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class StoreInventoryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InventoryRequest::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'warehouse_location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'source_type' => ['nullable', 'string', Rule::in(['work_order', 'ticket'])],
            'source_id' => [
                'nullable',
                'integer',
                'required_with:source_type',
                function ($attribute, $value, $fail) use ($companyId) {
                    if (! $value) {
                        return;
                    }
                    $sourceType = $this->input('source_type');
                    if ($sourceType === 'work_order') {
                        $exists = WorkOrder::where('id', $value)->where('company_id', $companyId)->exists();
                        if (! $exists) {
                            $fail('Work order yang dipilih tidak valid untuk perusahaan ini.');
                        }
                    } elseif ($sourceType === 'ticket') {
                        $exists = Ticket::where('id', $value)->where('company_id', $companyId)->exists();
                        if (! $exists) {
                            $fail('Ticket yang dipilih tidak valid untuk perusahaan ini.');
                        }
                    }
                },
            ],
            'needed_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'submit' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'items.*.source_location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'items.*.quantity_requested' => ['required', 'numeric', 'gt:0'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
