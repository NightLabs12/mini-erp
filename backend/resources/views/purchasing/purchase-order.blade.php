<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $order->doc_no }}</title>
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
        .totals { width: 45%; margin-left: auto; margin-top: 10px; }
        .totals td { border: none; padding: 3px 6px; }
        .totals tr:last-child td { border-top: 1px solid #999; font-weight: bold; }
        .footer { margin-top: 24px; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ $company }}</h1>
            <div class="muted">Purchase Order</div>
        </div>
        <div class="box">
            <strong>{{ $order->doc_no }}</strong><br>
            Tanggal: {{ $order->doc_date?->format('d/m/Y') }}<br>
            Status: {{ $order->status }}
        </div>
    </div>

    <table>
        <tr>
            <td width="50%">
                <strong>Supplier</strong><br>
                {{ $order->supplier?->code }} - {{ $order->supplier?->name }}<br>
                {{ $order->supplier?->address }}<br>
                {{ $order->supplier?->phone }}
            </td>
            <td width="50%">
                <strong>Gudang Penerima</strong><br>
                {{ $order->warehouse?->code }} - {{ $order->warehouse?->name }}<br>
                {{ $order->warehouse?->address }}<br>
                @if ($order->expected_date)
                    Perkiraan datang: {{ $order->expected_date->format('d/m/Y') }}
                @endif
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th width="4%">No</th>
                <th>Item</th>
                <th width="10%">Qty</th>
                <th width="9%">Satuan</th>
                <th width="14%" class="right">Harga</th>
                <th width="8%" class="right">Diskon</th>
                <th width="15%" class="right">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items->sortBy([['line_no', 'asc'], ['id', 'asc']]) as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->item?->sku }} - {{ $item->item?->name }}</td>
                    <td class="right">{{ number_format((float) $item->qty, 4, ',', '.') }}</td>
                    <td>{{ $item->unit?->code }}</td>
                    <td class="right">{{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format((float) $item->discount_pct, 2, ',', '.') }}%</td>
                    <td class="right">{{ number_format((float) $item->line_total, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="right">{{ number_format((float) $order->subtotal, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Diskon</td>
            <td class="right">{{ number_format((float) $order->discount_amount, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>PPN {{ number_format((float) $order->tax_rate, 2, ',', '.') }}%</td>
            <td class="right">{{ number_format((float) $order->tax_amount, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Total</td>
            <td class="right">{{ number_format((float) $order->total, 2, ',', '.') }}</td>
        </tr>
    </table>

    @if ($order->notes)
        <p><strong>Catatan:</strong> {{ $order->notes }}</p>
    @endif

    <div class="footer">
        Dokumen dibuat oleh sistem Mini ERP. Harga dan total dalam Rupiah.
    </div>
</body>
</html>
