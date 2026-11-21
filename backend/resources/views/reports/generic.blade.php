<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { color: #555; margin-bottom: 12px; }
        .meta span { margin-right: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bbb; padding: 4px 6px; vertical-align: top; }
        th { background: #eee; text-align: left; }
        td.num { text-align: right; }
        .footer { margin-top: 16px; font-size: 9px; color: #666; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>

    <div class="meta">
        @foreach ($meta as $item)
            <span>{{ $item['label'] }}: {{ $item['value'] }}</span>
        @endforeach
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(count($headings), 1) }}">Tidak ada data.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dokumen dibuat oleh sistem Mini ERP pada {{ now()->format('d/m/Y H:i') }}.
    </div>
</body>
</html>
