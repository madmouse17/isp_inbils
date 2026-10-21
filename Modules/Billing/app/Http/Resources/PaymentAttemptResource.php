<?php

namespace Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Modules\Billing\Models\PaymentAttempt;
use Spatie\Activitylog\Models\Activity;

/**
 * Staff view of an online payment attempt. The Snap token, its URL and the stored notification are
 * left out: whoever holds the URL can pay the invoice. The two labels are worded from the stored notification here, and
 * carry no number or reference of it beyond the last four digits of a card.
 *
 * @mixin PaymentAttempt
 */
class PaymentAttemptResource extends JsonResource
{
    /** Midtrans payment types as staff say them. A bank transfer, a card and a store name their bank, last digits or store (methodLabel). */
    private const METHODS = [
        'echannel' => 'VA Mandiri',
        'gopay' => 'GoPay',
        'shopeepay' => 'ShopeePay',
        'qris' => 'QRIS',
        'akulaku' => 'Akulaku',
        'kredivo' => 'Kredivo',
        'bca_klikpay' => 'BCA KlikPay',
        'bri_epay' => 'BRI e-Pay',
        'cimb_clicks' => 'CIMB Clicks',
        'danamon_online' => 'Danamon Online',
        'uob_ezpay' => 'UOB EZ Pay',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_id' => $this->order_id,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            /** The reason in Indonesian: the stored one is English or a Midtrans word (`deny`, `cancel`) for most outcomes. */
            'reason_label' => $this->reasonLabel(),
            'amount' => $this->amount,
            'transaction_id' => $this->transaction_id,
            'payment_type' => $this->payment_type,
            /** How the customer paid, as staff say it: "VA BCA", "Kartu •••• 1114", "GoPay"; null until a method was picked. */
            'method_label' => $this->methodLabel(),
            'payment_id' => $this->payment?->public_id,
            'expires_at' => $this->expires_at->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            /** Who closed an exception as refunded, when, and the reference and note they gave; null for any other status. */
            'refund' => $this->status === PaymentAttempt::STATUS_REFUNDED ? [
                'at' => $this->resolved_at?->toIso8601String(),
                'by' => $this->resolver?->name,
                'reference' => $this->resolution_reference,
                'note' => $this->resolution_note,
            ] : null,
            /** What Midtrans called about this page, newest first: when, what it said and what Inbils answered. Empty unless the page loaded the trail. */
            'callbacks' => $this->resource->relationLoaded('callbacks')
                ? $this->resource->getRelation('callbacks')->map(fn (Activity $row): array => [
                    'at' => $row->created_at?->toIso8601String(),
                    'transaction_status' => $row->properties->get('transaction_status'),
                    'status_code' => $row->properties->get('status_code'),
                    'fraud_status' => $row->properties->get('fraud_status'),
                    'http' => $row->properties->get('http'),
                ])->values()->all()
                : [],
        ];
    }

    private function methodLabel(): ?string
    {
        $type = $this->payment_type;
        $notification = $this->last_notification ?? [];

        return match (true) {
            $type === null => null,
            $type === 'bank_transfer' => ($bank = self::bankOf($notification)) === null ? 'Transfer bank' : 'VA '.$bank,
            $type === 'credit_card' => ($digits = self::cardEnd($notification)) === null ? 'Kartu kredit' : 'Kartu •••• '.$digits,
            $type === 'cstore' => self::wordOf($notification, 'store') ?? 'Gerai',
            default => self::METHODS[$type] ?? Str::headline($type),
        };
    }

    /**
     * Why the attempt is in the state it is, in Indonesian. The wording matches what PaymentAttemptService stores; a reason
     * it does not know (it is Indonesian already, or new) is shown as it is, so nothing is ever hidden.
     */
    private function reasonLabel(): ?string
    {
        $reason = $this->status_reason;

        return match (true) {
            $reason === null => null,
            $reason === 'fraud_status deny' => 'Ditolak sistem deteksi penipuan',
            $reason === 'deny' => $this->bankRefusal(),
            $reason === 'cancel' => 'Dibatalkan di Midtrans',
            $reason === 'failure' => 'Gagal diproses Midtrans',
            str_starts_with($reason, 'gross_amount') => 'Jumlah dari Midtrans tidak sama dengan jumlah percobaan ini, belum dibukukan',
            str_contains($reason, 'after settlement') => 'Midtrans melaporkan uang kembali setelah pembayaran dibukukan, batalkan pembayarannya bila uang memang dikembalikan',
            str_contains($reason, 'never settled here') => 'Midtrans melaporkan uang kembali untuk pembayaran yang tidak pernah dibukukan di sini',
            $reason === 'Payment page expired without a Midtrans transaction.' => 'Halaman bayar kedaluwarsa sebelum pelanggan memilih metode',
            $reason === 'Billing refused the payment.' => 'Penagihan menolak pembayaran ini',
            default => $reason,
        };
    }

    /** What the issuer said about a refused card or transfer ("Do not honour (05)"), else a plain "Ditolak bank". */
    private function bankRefusal(): string
    {
        $message = data_get($this->last_notification, 'channel_response_message');
        $code = data_get($this->last_notification, 'channel_response_code');

        if (! is_string($message) || $message === '') {
            return 'Ditolak bank atau penerbit kartu';
        }

        return 'Ditolak bank: '.Str::limit($message, 80, '').(is_string($code) && $code !== '' ? ' ('.Str::limit($code, 10, '').')' : '');
    }

    /**
     * The bank of a virtual account: "BCA", "BNI", "Permata". Letters only, whatever the notification holds.
     *
     * @param  array<string, mixed>  $notification
     */
    private static function bankOf(array $notification): ?string
    {
        $bank = data_get($notification, 'va_numbers.0.bank');

        return match (true) {
            is_string($bank) && preg_match('/^[a-z]{2,20}$/i', $bank) === 1 => strtoupper($bank),
            isset($notification['permata_va_number']) => 'Permata',
            default => null,
        };
    }

    /**
     * The last four digits of a masked card number ("48111111-1114"), the one part of it staff may read.
     *
     * @param  array<string, mixed>  $notification
     */
    private static function cardEnd(array $notification): ?string
    {
        $masked = data_get($notification, 'masked_card');

        return is_string($masked) && preg_match('/(\d{4})$/', $masked, $digits) === 1 ? $digits[1] : null;
    }

    /**
     * A name Midtrans sends as one lowercase word ("alfamart"), capitalised; null for anything that is not a short word.
     *
     * @param  array<string, mixed>  $notification
     */
    private static function wordOf(array $notification, string $key): ?string
    {
        $word = data_get($notification, $key);

        return is_string($word) && preg_match('/^[a-z]{3,20}$/i', $word) === 1 ? ucfirst(strtolower($word)) : null;
    }
}
