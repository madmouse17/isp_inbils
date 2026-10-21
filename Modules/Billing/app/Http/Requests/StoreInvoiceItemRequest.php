<?php

namespace Modules\Billing\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\Product;

class StoreInvoiceItemRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['product_id' => Product::class];
    }

    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice && ($this->user()?->can('update', $invoice) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            // No tax_rate: the line takes the PPN of the company (BillingService::addItem), whatever a request names.
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'product_id' => 'barang',
            'description' => 'deskripsi',
            'quantity' => 'kuantitas',
            'unit_price' => 'harga satuan',
            'discount_amount' => 'diskon',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_id.exists' => 'Barang yang dipilih tidak ditemukan.',
            'description.required' => 'Deskripsi wajib diisi.',
            'quantity.required' => 'Kuantitas wajib diisi.',
            'quantity.min' => 'Kuantitas minimal :min.',
            'unit_price.required' => 'Harga satuan wajib diisi.',
        ];
    }
}
