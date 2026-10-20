<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        .header { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 16px; }
        .company-name { font-size: 18px; font-weight: bold; }
        .row { width: 100%; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.items th, table.items td { border: 1px solid #999; padding: 6px 8px; }
        table.items th { background: #eee; text-align: left; }
        .right { text-align: right; }
        .totals td { border: none; padding: 3px 8px; }
        .terbilang { font-style: italic; margin-top: 8px; }
        .status { font-size: 14px; font-weight: bold; text-transform: uppercase; }
        .muted { color: #666; }
    </style>
</head>
<body>
    @php
        // Same labels as INVOICE_STATUS in resources/js/lib/statuses/invoice.ts.
        $statusLabels = [
            'draft' => 'Draf',
            'sent' => 'Belum dibayar',
            'partial' => 'Sebagian',
            'paid' => 'Lunas',
            'overdue' => 'Lewat jatuh tempo',
            'cancelled' => 'Dibatalkan',
        ];

        // The same rule as formatRupiah in resources/js/lib/format.ts: whole rupiah, and cents only when the stored amount has them.
        $rp = fn (string|int|float $amount): string => 'Rp '.\App\Support\Money::display($amount);
    @endphp
    <div class="header">
        <div class="company-name">{{ $company?->name ?? '' }}</div>
        <div class="muted">{{ $company?->address ?? '' }}</div>
    </div>

    <table class="row">
        <tr>
            <td>
                <strong>TAGIHAN {{ $invoice->number }}</strong><br>
                Tanggal: {{ $invoice->issue_date->format('d/m/Y') }}<br>
                Jatuh tempo: {{ $invoice->due_date->format('d/m/Y') }}<br>
                @if ($invoice->billing_period_start)
                    Periode: {{ $invoice->billing_period_start->format('d/m/Y') }} s/d {{ $invoice->billing_period_end->format('d/m/Y') }}<br>
                @endif
                <span class="status">{{ $statusLabels[$invoice->status] ?? $invoice->status }}</span>
            </td>
            <td class="right">
                <strong>Kepada:</strong><br>
                {{ $party['customer_name'] }}<br>
                @if ($party['customer_address'])
                    {{ $party['customer_address'] }}<br>
                @endif
                @if ($party['customer_tax_id'])
                    NPWP: {{ $party['customer_tax_id'] }}<br>
                @endif
                @if ($invoice->subscription)
                    {{ $invoice->subscription->code }} - {{ $party['package_name'] }}
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>Deskripsi</th><th class="right">Jml</th><th class="right">Harga</th><th class="right">Jumlah</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format($item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="right">{{ $rp($item->unit_price) }}</td>
                    <td class="right">{{ $rp($item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="row totals" style="margin-top: 8px;">
        <tr><td class="right" style="width: 80%;">Subtotal</td><td class="right">{{ $rp($invoice->subtotal) }}</td></tr>
        @if ((float) $invoice->tax_amount > 0)
            <tr><td class="right">PPN</td><td class="right">{{ $rp($invoice->tax_amount) }}</td></tr>
        @endif
        @if ((float) $invoice->discount_amount > 0)
            <tr><td class="right">Diskon</td><td class="right">- {{ $rp($invoice->discount_amount) }}</td></tr>
        @endif
        <tr><td class="right"><strong>Total</strong></td><td class="right"><strong>{{ $rp($invoice->total) }}</strong></td></tr>
        @if ((float) $invoice->paid_amount > 0)
            <tr><td class="right">Terbayar</td><td class="right">{{ $rp($invoice->paid_amount) }}</td></tr>
            <tr><td class="right"><strong>Sisa</strong></td><td class="right"><strong>{{ $rp($invoice->sisa) }}</strong></td></tr>
        @endif
    </table>

    <p class="terbilang">Terbilang: {{ ucfirst($terbilang) }}</p>

    @if ($bankInfo)
        <p><strong>Pembayaran:</strong><br>{!! nl2br(e($bankInfo)) !!}</p>
    @endif
</body>
</html>
