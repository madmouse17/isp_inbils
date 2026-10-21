<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        .header { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 16px; }
        .company-name { font-size: 18px; font-weight: bold; }
        .title { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
        .row { width: 100%; }
        table.payments { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.payments th, table.payments td { border: 1px solid #999; padding: 6px 8px; }
        table.payments th { background: #eee; text-align: left; }
        .right { text-align: right; }
        .totals td { border: none; padding: 3px 8px; }
        .terbilang { font-style: italic; margin-top: 8px; }
        .status { font-size: 14px; font-weight: bold; text-transform: uppercase; }
        .muted { color: #666; }
    </style>
</head>
<body>
    @php
        // The same rule as formatRupiah in resources/js/lib/format.ts: whole rupiah, and cents only when the stored amount has them.
        $rp = fn (string|int|float $amount): string => 'Rp '.\App\Support\Money::display($amount);

        // Payments are stored in UTC; the receipt shows the day and time in the company's own zone.
        $timezone = $company?->timezone ?: (string) config('app.timezone');
        $at = fn ($moment): string => $moment->copy()->setTimezone($timezone)->format('d/m/Y H:i');

        // The controllers hand the payments over oldest first: the last one is the one that paid the invoice off.
        $paidOff = $payments->last()?->paid_at;
    @endphp
    <div class="header">
        <div class="company-name">{{ $company?->name ?? '' }}</div>
        <div class="muted">{{ $company?->address ?? '' }}</div>
    </div>

    <table class="row">
        <tr>
            <td>
                <span class="title">KUITANSI PEMBAYARAN</span><br>
                <strong>No. KWT/{{ $invoice->number }}</strong><br>
                @if ($paidOff)
                    Tanggal: {{ $paidOff->copy()->setTimezone($timezone)->format('d/m/Y') }}<br>
                @endif
                <span class="status">Lunas</span>
            </td>
            <td class="right">
                <strong>Telah diterima dari:</strong><br>
                {{ $party['customer_name'] }}<br>
                @if ($party['customer_address'])
                    {{ $party['customer_address'] }}<br>
                @endif
                @if ($party['customer_tax_id'])
                    NPWP: {{ $party['customer_tax_id'] }}<br>
                @endif
            </td>
        </tr>
    </table>

    <p>
        <strong>Untuk pembayaran tagihan {{ $invoice->number }}</strong><br>
        @if ($invoice->billing_period_start)
            Periode: {{ $invoice->billing_period_start->format('d/m/Y') }} s/d {{ $invoice->billing_period_end->format('d/m/Y') }}<br>
        @endif
        @if ($invoice->subscription)
            {{ $invoice->subscription->code }} - {{ $party['package_name'] }}
        @endif
    </p>

    <table class="payments">
        <thead>
            <tr><th>Tanggal bayar</th><th>Metode</th><th>Referensi</th><th class="right">Jumlah</th></tr>
        </thead>
        <tbody>
            @foreach ($payments as $payment)
                <tr>
                    <td>{{ $at($payment->paid_at) }}</td>
                    <td>{{ $payment->methodLabel() }}</td>
                    {{-- A staff-typed reference stays internal; only a gateway order id is printed. --}}
                    <td>{{ $payment->customerReference() ?? '-' }}</td>
                    <td class="right">{{ $rp($payment->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="row totals" style="margin-top: 8px;">
        <tr><td class="right" style="width: 80%;"><strong>Total dibayar</strong></td><td class="right"><strong>{{ $rp($invoice->paid_amount) }}</strong></td></tr>
    </table>

    <p class="terbilang">Terbilang: {{ ucfirst($terbilang) }}</p>
</body>
</html>
