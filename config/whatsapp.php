<?php

/*
|--------------------------------------------------------------------------
| WhatsApp notifications: templates, defaults and limits
|--------------------------------------------------------------------------
|
| The sender (token, phone number id, app secret) is install-wide and lives in config/services.php.
| Everything here is code, not secret. The app never sends free text: every message is one of the
| utility templates below, created and approved by the owner in Meta WhatsApp Manager from the wording in
| docs/business/*.md. `parameters` names the values in the order the template body expects them
| ({{1}}, {{2}}, ...); changing a name list, the order or the language means a new template approval.
|
| `batch` events (invoice issued, reminders) wait out the company's quiet hours; the others are sent
| immediately because a person is waiting for them.
|
*/

return [

    // Company-local quiet window for batch events; each company may override it in its settings.
    'quiet_start' => '21:00',
    'quiet_end' => '07:00',

    // Sent messages per company per calendar month before further ones are skipped as budget_exceeded.
    'monthly_cap' => 1000,

    // Queue rate limit per company (Cloud API throughput is far higher).
    'rate_per_second' => 20,

    'events' => [
        'invoice_issued' => [
            'label' => 'Tagihan terbit',
            'audience' => 'customer',
            'template' => 'tagihan_terbit',
            'language' => 'id',
            'parameters' => ['company_name', 'invoice_number', 'total', 'due_date', 'portal_url'],
            'batch' => true,
        ],
        'invoice_reminder' => [
            'label' => 'Pengingat jatuh tempo (H-3, H-1, H+1)',
            'audience' => 'customer',
            'template' => 'tagihan_pengingat',
            'language' => 'id',
            'parameters' => ['company_name', 'invoice_number', 'remaining_balance', 'due_date', 'portal_url'],
            'batch' => true,
        ],
        'payment_received' => [
            'label' => 'Pembayaran diterima',
            'audience' => 'customer',
            'template' => 'pembayaran_diterima',
            'language' => 'id',
            'parameters' => ['company_name', 'invoice_number', 'amount', 'remaining_balance', 'paid_date'],
            'batch' => false,
        ],
        'ticket_created' => [
            'label' => 'Tiket dibuat',
            'audience' => 'customer',
            'template' => 'tiket_dibuat',
            'language' => 'id',
            'parameters' => ['company_name', 'ticket_code', 'title'],
            'batch' => false,
        ],
        'ticket_assigned' => [
            'label' => 'Tiket mulai ditangani',
            'audience' => 'customer',
            'template' => 'tiket_ditangani',
            'language' => 'id',
            'parameters' => ['company_name', 'ticket_code', 'title', 'handler_first_name'],
            'batch' => false,
        ],
        'ticket_resolved' => [
            'label' => 'Tiket selesai',
            'audience' => 'customer',
            'template' => 'tiket_selesai',
            'language' => 'id',
            'parameters' => ['company_name', 'ticket_code', 'title', 'resolved_date'],
            'batch' => false,
        ],
        'spk_scheduled' => [
            'label' => 'Penugasan SPK ke teknisi',
            'audience' => 'technician',
            'template' => 'spk_penugasan_teknisi',
            'language' => 'id',
            'parameters' => ['spk_code', 'title', 'scheduled_date', 'spk_url'],
            'batch' => false,
        ],
        'spk_scheduled_customer' => [
            'label' => 'Jadwal kunjungan teknisi ke pelanggan',
            'audience' => 'customer',
            'template' => 'spk_jadwal_pelanggan',
            'language' => 'id',
            'parameters' => ['company_name', 'spk_code', 'scheduled_date', 'technician_first_name'],
            'batch' => false,
        ],
        'access_link_notice' => [
            'label' => 'Info link akses portal (tanpa link)',
            'audience' => 'customer',
            'template' => 'akses_portal_info',
            'language' => 'id',
            'parameters' => ['company_name', 'masked_email'],
            'batch' => false,
        ],
    ],

];
