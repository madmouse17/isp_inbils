<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 2cm 2cm 2.4cm 2cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; line-height: 1.55; color: #111; }
        h1 { font-size: 20px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 2px solid #333; page-break-before: always; }
        h2 { font-size: 15px; margin: 22px 0 8px; }
        h3 { font-size: 12.5px; margin: 16px 0 6px; }
        h4 { font-size: 11px; margin: 12px 0 4px; }
        h1, h2, h3, h4 { page-break-after: avoid; }
        p, ul, ol { margin: 0 0 8px; }
        li { margin-bottom: 3px; }
        img { max-width: 100%; border: 1px solid #ccc; margin: 4px 0 10px; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; }
        th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        code { font-family: DejaVu Sans Mono, monospace; background: #f1f1f1; }
        pre { background: #f1f1f1; padding: 6px 8px; white-space: pre-wrap; }
        blockquote { margin: 0 0 8px; padding: 2px 10px; border-left: 3px solid #999; color: #444; }
        a { color: #1d4ed8; text-decoration: none; }
        .cover { text-align: center; padding-top: 260px; }
        .cover .brand { font-size: 12px; letter-spacing: 2px; text-transform: uppercase; color: #555; }
        .cover .title { font-size: 30px; font-weight: bold; margin: 14px 0 20px; }
        .cover .version { font-size: 10px; color: #666; }
        .toc { page-break-before: always; }
        .toc h2 { margin-top: 0; }
        .toc div { margin-bottom: 2px; }
        .toc .l1 { margin-top: 6px; font-weight: bold; }
        .toc .l2 { padding-left: 16px; }
    </style>
</head>
<body>
    <div class="cover">
        <div class="brand">Inbils ISP ERP</div>
        <div class="title">{{ $title }}</div>
        <div class="version">Versi {{ $version }}</div>
    </div>

    <div class="toc">
        <h2>Daftar Isi</h2>
        @foreach ($toc as $entry)
            <div class="l{{ $entry['level'] }}"><a href="#{{ $entry['id'] }}">{{ $entry['text'] }}</a></div>
        @endforeach
    </div>

    {!! $body !!}
</body>
</html>
