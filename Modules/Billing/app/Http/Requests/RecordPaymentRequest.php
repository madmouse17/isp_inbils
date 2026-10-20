<?php

namespace Modules\Billing\Http\Requests;

use App\Services\Core\CompanyService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Billing\Models\Invoice;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.payment.record') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Whole rupiah, so an invoice balance stays whole. The one exception: the exact balance of an invoice issued
            // before whole-rupiah billing, which carries cents and could never be cleared otherwise.
            'amount' => ['bail', 'required', 'numeric', 'min:0.01', function (string $attribute, mixed $value, Closure $fail): void {
                $invoice = $this->route('invoice');

                if (! preg_match('/^\d+(\.0{1,2})?$/', (string) $value) && ! ($invoice instanceof Invoice && Money::compare((float) $value, $invoice->sisa) === 0)) {
                    $fail('Jumlah pembayaran harus rupiah penuh, tanpa sen.');
                }
            }],
            'method' => ['required', 'string', 'in:cash,transfer,cheque,other'],
            'paid_at' => $this->paidAtRules(),
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'amount' => 'jumlah',
            'method' => 'metode',
            'paid_at' => 'tanggal bayar',
            'reference' => 'referensi',
            'notes' => 'catatan',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'Jumlah wajib diisi.',
            'amount.min' => 'Jumlah harus lebih dari nol.',
            'method.required' => 'Metode wajib dipilih.',
            'method.in' => 'Metode yang dipilih tidak dikenal.',
            'paid_at.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
            'paid_at.before_or_equal' => 'Tanggal bayar tidak boleh setelah hari ini.',
            'paid_at.after_or_equal' => 'Tanggal bayar tidak boleh sebelum tanggal terbit tagihan.',
        ];
    }

    /**
     * The day the money arrived, on the company's calendar: not after today and not before the invoice was issued. An invoice
     * issued for a later day can still be paid today, so the lower bound never passes today.
     *
     * @return list<string>
     */
    private function paidAtRules(): array
    {
        $today = $this->companyToday();
        $invoice = $this->route('invoice');

        return [
            'bail', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today,
            ...($invoice instanceof Invoice ? ['after_or_equal:'.min($invoice->issue_date->toDateString(), $today)] : []),
        ];
    }

    /**
     * Where to book the payment: the chosen day at the company's clock time of recording, so per-day figures stay right without
     * a time field (OD8). Null means now: no day was chosen, or the day is today.
     */
    public function paidAt(): ?CarbonImmutable
    {
        $day = $this->validated('paid_at');

        if ($day === null || $day === $this->companyToday()) {
            return null;
        }

        $zone = $this->companyZone();

        return CarbonImmutable::parse($day.' '.now($zone)->format('H:i:s'), $zone);
    }

    private function companyZone(): string
    {
        return CompanyService::current()?->timezone ?: (string) config('app.timezone');
    }

    private function companyToday(): string
    {
        return now($this->companyZone())->toDateString();
    }
}
