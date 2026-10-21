<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 32px 48px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        .kop { border-bottom: 2px solid #333; margin-bottom: 10px; }
        .kop td { padding: 0 0 8px; vertical-align: middle; }
        .logo { height: 36px; max-width: 120px; margin-right: 10px; }
        .company { font-size: 14px; font-weight: bold; }
        .printed { text-align: right; color: #555; font-size: 9px; }
        h1 { font-size: 15px; margin: 0 0 4px; }
        .meta { color: #555; font-size: 9px; margin: 0 0 2px; }
        .data { margin-top: 8px; }
        .data th, .data td { border-bottom: 1px solid #d4d4d8; padding: 4px 6px; text-align: left; vertical-align: top; }
        .data th { background: #f3f4f6; border-bottom: 1px solid #9ca3af; }
        .data .figure { text-align: right; white-space: nowrap; }
    </style>
</head>
<body>
    @php
        // The view is also rendered with a title, headers and rows alone: everything else is optional.
        $figures = $figures ?? [];
        $filters = $filters ?? [];
    @endphp
    <table class="kop">
        <tr>
            @if (!empty($company['logo']))
                <td style="width: 1%;"><img class="logo" src="{{ $company['logo'] }}" alt=""></td>
            @endif
            <td class="company">{{ $company['name'] ?? '' }}</td>
            <td class="printed">
                @if (!empty($printedAt))
                    Dicetak {{ $printedAt }}@if (!empty($printedBy)) oleh {{ $printedBy }}@endif
                @endif
            </td>
        </tr>
    </table>
    <h1>{{ $title }}</h1>
    @if (!empty($period))
        <div class="meta">Periode: {{ $period }}</div>
    @endif
    @if (!empty($filters))
        <div class="meta">Filter: {{ implode('; ', $filters) }}</div>
    @endif
    <div class="meta">
        Baris: {{ count($rows) }}@if(!empty($capped)) (dibatasi sampai {{ $maxRows }})@endif
    </div>
    @if(!empty($capped))
        <div class="meta">PDF dibatasi {{ $maxRows }} baris. Untuk data lengkap, unduh CSV atau persempit periode.</div>
    @endif
    <table class="data">
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th @class(['figure' => in_array($header, $figures, true)])>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($headers as $header)
                        <td @class(['figure' => in_array($header, $figures, true)])>{{ $row[$header] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(count($headers), 1) }}">Tidak ada data.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
