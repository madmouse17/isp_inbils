<?php

namespace Modules\Inventory\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Support\InventoryRequestSources;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class StoreInventoryRequestRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string|\Closure> */
    protected function publicIdFields(): array
    {
        return [
            'warehouse_location_id' => Location::class,
            'source_id' => fn (array $input): ?string => is_string($type = $input['source_type'] ?? null) ? (['work_order' => WorkOrder::class, 'ticket' => Ticket::class][$type] ?? null) : null,
            'items.*.product_id' => Product::class,
            'items.*.source_location_id' => Location::class,
        ];
    }

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
                function ($attribute, $value, $fail) {
                    // The pickers read the same query: only a record the person may already open can be named.
                    $user = $this->user();

                    if ($this->input('source_type') === 'work_order' && ! InventoryRequestSources::workOrderBase($user)->whereKey($value)->exists()) {
                        $fail('SPK yang dipilih tidak valid untuk perusahaan ini.');
                    } elseif ($this->input('source_type') === 'ticket' && ! InventoryRequestSources::ticketBase($user)->whereKey($value)->exists()) {
                        $fail('Tiket yang dipilih tidak valid untuk perusahaan ini.');
                    }
                },
            ],
            'needed_at' => ['nullable', 'date_format:Y-m-d'],
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

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'source_type' => 'jenis rujukan',
            'source_id' => 'rujukan',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'needed_at.date_format' => 'Tanggal dibutuhkan harus berupa tanggal yang valid.',
            // A source id that names no record the company can see is refused with the text of the closure rule in rules().
            'source_id.exists' => $this->input('source_type') === 'ticket'
                ? 'Tiket yang dipilih tidak valid untuk perusahaan ini.'
                : 'SPK yang dipilih tidak valid untuk perusahaan ini.',
        ];
    }
}
