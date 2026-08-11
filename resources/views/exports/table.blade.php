<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .meta { margin-bottom: 10px; color: #555; font-size: 11px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        Rows: {{ count($rows) }}@if(!empty($capped)) (capped at {{ $maxRows }})@endif
    </div>
    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($headers as $header)
                        <td>{{ $row[$header] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(count($headers), 1) }}">No data</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
