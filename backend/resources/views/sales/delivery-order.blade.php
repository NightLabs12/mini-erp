<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $delivery->doc_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #666; }
        .header { display: flex; justify-content: space-between; margin-bottom: 18px; }
        .box { border: 1px solid #ccc; padding: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px; vertical-align: top; }
        th { background: #f2f2f2; text-align: left; }
        .right { text-align: right; }
        .footer { margin-top: 28px; font-size: 10px; color: #666; }
        .signature { margin-top: 40px; width: 100%; }
        .signature td { border: none; padding-top: 40px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ $company }}</h1>
            <div class="muted">Surat Jalan / Delivery Order</div>
        </div>
        <div class="box">
            <strong>{{ $delivery->doc_no }}</strong><br>
            Tanggal: {{ $delivery->doc_date?->format('d/m/Y') }}<br>
            Status: {{ $delivery->status }}
        </div>
    </div>

    <table>
        <tr>
            <td width="50%">
                <strong>Customer</strong><br>
                {{ $delivery->customer?->code }} - {{ $delivery->customer?->name }}<br>
                {{ $delivery->customer?->address }}<br>
                {{ $delivery->customer?->phone }}
            </td>
            <td width="50%">
                <strong>Gudang Pengirim</strong><br>
                {{ $delivery->warehouse?->code }} - {{ $delivery->warehouse?->name }}<br>
                @if ($delivery->salesOrder)
                    SO: {{ $delivery->salesOrder->doc_no }}
                @endif
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th width="4%">No</th>
                <th>Item</th>
                <th width="12%">Qty</th>
                <th width="10%">Satuan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($delivery->items->sortBy([['line_no', 'asc'], ['id', 'asc']]) as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->item?->sku }} - {{ $item->item?->name }}</td>
                    <td class="right">{{ number_format((float) $item->qty, 4, ',', '.') }}</td>
                    <td>{{ $item->unit?->code }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($delivery->notes)
        <p><strong>Catatan:</strong> {{ $delivery->notes }}</p>
    @endif

    <table class="signature">
        <tr>
            <td>Pengirim</td>
            <td>Penerima</td>
        </tr>
    </table>

    <div class="footer">
        Dokumen dibuat oleh sistem Mini ERP. Barang telah diterima dalam keadaan baik.
    </div>
</body>
</html>
