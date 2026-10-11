<?php

namespace Database\Seeders\Demo;

use App\Actions\SetCustomerWhatsAppConsentAction;
use App\Models\Core\Customer;
use App\Models\Core\WhatsAppMessage;
use App\Services\WhatsApp\PhoneNumber;
use Modules\Billing\Models\Invoice;

/**
 * WhatsApp state for the demo company: consent recorded by staff for a few customers (one withdrew it, one has
 * a number that is no mobile number) and a handful of delivery-log rows in every status. The company switch
 * stays off and no sender is configured, so nothing here sends; the log rows hold ids, a keyed hash and the
 * last four digits only, as the channel writes them. Skips what exists.
 */
final class WhatsAppDemo
{
    /** Customer code => number the customer gave (valid mobile numbers, one that is not). */
    private const CONSENTED = [
        'DEMO-C002' => '081230000002',
        'DEMO-C005' => '081230000005',
        'DEMO-C008' => '081230000008',
        'DEMO-C011' => '081230000011',
        'DEMO-C017' => '0812-99',
    ];

    private const WITHDRAWN = ['DEMO-C014' => '081230000014'];

    /** [customer code, event, template, status, skip reason, error code, hours ago]; DEMO-C020 never gave consent. */
    private const LOG = [
        ['DEMO-C002', 'invoice_issued', 'tagihan_terbit', 'read', null, null, 70],
        ['DEMO-C002', 'payment_received', 'pembayaran_diterima', 'delivered', null, null, 46],
        ['DEMO-C005', 'invoice_reminder', 'tagihan_pengingat', 'sent', null, null, 20],
        ['DEMO-C008', 'invoice_issued', 'tagihan_terbit', 'failed', null, '131026', 30],
        ['DEMO-C011', 'invoice_reminder', 'tagihan_pengingat', 'queued', null, null, 1],
        ['DEMO-C014', 'invoice_reminder', 'tagihan_pengingat', 'skipped', 'opted_out', null, 12],
        ['DEMO-C017', 'invoice_issued', 'tagihan_terbit', 'skipped', 'invalid_phone', null, 8],
        ['DEMO-C020', 'invoice_issued', 'tagihan_terbit', 'skipped', 'no_consent', null, 5],
    ];

    public static function seed(DemoContext $c): void
    {
        $manager = $c->user('manager');

        foreach ([...self::CONSENTED, ...self::WITHDRAWN] as $code => $phone) {
            $customer = Customer::query()->where('code', $code)->first();

            if ($customer === null || $customer->whatsapp_opt_in_at !== null) {
                continue;
            }

            $customer->update(['phone' => $phone]);
            SetCustomerWhatsAppConsentAction::execute($customer, true, $manager);

            if (isset(self::WITHDRAWN[$code])) {
                SetCustomerWhatsAppConsentAction::execute($customer->refresh(), false, $manager);
            }
        }

        foreach (self::LOG as [$code, $event, $template, $status, $skipReason, $errorCode, $hoursAgo]) {
            self::log($code, $event, $template, $status, $skipReason, $errorCode, $hoursAgo);
        }
    }

    private static function log(string $code, string $event, string $template, string $status, ?string $skipReason, ?string $errorCode, int $hoursAgo): void
    {
        $customer = Customer::query()->where('code', $code)->first();
        $invoiceId = $customer === null ? null : Invoice::query()->where('customer_id', $customer->id)->orderBy('id')->value('id');
        $dedupeKey = "demo:{$event}:{$code}";

        if ($customer === null || WhatsAppMessage::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return;
        }

        $phone = PhoneNumber::normalizeIndonesianMobile($customer->phone);
        $at = now()->subHours($hoursAgo);
        $handedOver = in_array($status, ['sent', 'delivered', 'read'], true);

        $row = new WhatsAppMessage([
            'recipient_type' => 'customer',
            'recipient_id' => $customer->id,
            'event' => $event,
            'subject_type' => $invoiceId === null ? null : 'invoice',
            'subject_id' => $invoiceId,
            'template' => $template,
            'language' => 'id',
            'dedupe_key' => $dedupeKey,
            'status' => $status,
            'skip_reason' => $skipReason,
            'error_code' => $errorCode,
            'provider_message_id' => $handedOver ? 'wamid.DEMO'.strtoupper(substr(hash('sha256', $dedupeKey), 0, 24)) : null,
            'recipient_hash' => $phone === null ? null : hash_hmac('sha256', $phone, (string) config('app.key')),
            'recipient_last4' => $phone === null ? null : substr($phone, -4),
            'billable' => $handedOver ? true : null,
            'pricing_category' => $handedOver ? 'utility' : null,
            'sent_at' => $handedOver ? $at : null,
            'delivered_at' => in_array($status, ['delivered', 'read'], true) ? $at->copy()->addMinute() : null,
            'read_at' => $status === 'read' ? $at->copy()->addMinutes(9) : null,
            'failed_at' => $status === 'failed' ? $at->copy()->addSeconds(30) : null,
        ]);
        $row->created_at = $at;
        $row->updated_at = $at;
        $row->save();
    }
}
