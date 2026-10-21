<?php

namespace Modules\Billing\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Rules\MatchesParentAttribute;
use App\Services\Core\CompanyService;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\Product;

class StoreInvoiceRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** What one line may come to: 20 lines under it still fit the decimal(15,2) columns of the invoice. */
    private const MAX_LINE = '100000000000';

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['customer_id' => Customer::class, 'subscription_id' => ServiceSubscription::class, 'items.*.product_id' => Product::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('billing.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            // bail: the pair rule reads the row without the company boundary, so it never runs for an id the company does not own.
            'subscription_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)->withoutTrashed(),
                new MatchesParentAttribute('service_subscriptions', 'customer_id', 'customer_id'),
            ],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            // The controller issues the invoice today when no date is typed, so the due date is held against today then.
            'due_date' => ['bail', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.($this->filled('issue_date') ? 'issue_date' : now()->toDateString())],
            'notes' => ['nullable', 'string', 'max:5000'],
            // The lines of the one form (F5-U02): optional, so a draft can still be started empty and filled on its page. The tax is the company's, never typed.
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100000'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000000'],
            'items.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', $companyId)],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // The field rules come first: a line that is not a number has no amount to compare.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('items', []) as $index => $item) {
                if (Money::compare(Money::mul($item['quantity'], $item['unit_price']), self::MAX_LINE) > 0) {
                    $validator->errors()->add("items.{$index}.unit_price", 'Jumlah baris terlalu besar.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'customer_id' => 'pelanggan',
            'subscription_id' => 'langganan',
            'issue_date' => 'tanggal terbit',
            'due_date' => 'jatuh tempo',
            'notes' => 'catatan',
            'items' => 'item',
            'items.*.description' => 'deskripsi',
            'items.*.quantity' => 'kuantitas',
            'items.*.unit_price' => 'harga satuan',
            'items.*.product_id' => 'barang',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Pelanggan wajib dipilih.',
            'customer_id.exists' => 'Pelanggan yang dipilih tidak ditemukan.',
            'subscription_id.exists' => 'Langganan yang dipilih tidak ditemukan.',
            'issue_date.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
            'due_date.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
            'due_date.after_or_equal' => 'Jatuh tempo tidak boleh sebelum tanggal terbit.',
            'notes.max' => 'Catatan paling banyak :max karakter.',
            'items.max' => 'Satu tagihan paling banyak :max item.',
            'items.*.description.required' => 'Deskripsi item wajib diisi.',
            'items.*.quantity.required' => 'Kuantitas wajib diisi.',
            'items.*.quantity.min' => 'Kuantitas minimal :min.',
            'items.*.quantity.max' => 'Kuantitas paling banyak :max.',
            'items.*.quantity.decimal' => 'Kuantitas paling banyak dua angka di belakang koma.',
            'items.*.unit_price.required' => 'Harga satuan wajib diisi.',
            'items.*.unit_price.min' => 'Harga satuan tidak boleh negatif.',
            'items.*.unit_price.max' => 'Harga satuan terlalu besar.',
            'items.*.unit_price.decimal' => 'Harga satuan paling banyak dua angka di belakang koma.',
            'items.*.product_id.exists' => 'Barang yang dipilih tidak ditemukan.',
        ];
    }
}
