<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->doc_no }}</title>
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
            <div class="muted">Sales Invoice</div>
        </div>
        <div class="box">
            <strong>{{ $invoice->doc_no }}</strong><br>
            Tanggal: {{ $invoice->doc_date?->format('d/m/Y') }}<br>
            Jatuh tempo: {{ $invoice->due_date?->format('d/m/Y') }}<br>
            Status: {{ $invoice->status }} / {{ $invoice->mode }}
        </div>
    </div>

    <table>
        <tr>
            <td width="50%">
                <strong>Customer</strong><br>
                {{ $invoice->customer?->code }} - {{ $invoice->customer?->name }}<br>
                {{ $invoice->customer?->address }}<br>
                {{ $invoice->customer?->phone }}
            </td>
            <td width="50%">
                <strong>Informasi</strong><br>
                Termin: {{ $invoice->customer?->payment_term_days }} hari<br>
                @if ($invoice->notes)
                    Catatan: {{ $invoice->notes }}
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
                <th width="15%" class="right">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items->sortBy([['line_no', 'asc'], ['id', 'asc']]) as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->item?->sku }} - {{ $item->item?->name }}</td>
                    <td class="right">{{ number_format((float) $item->qty, 4, ',', '.') }}</td>
                    <td>{{ $item->unit?->code }}</td>
                    <td class="right">{{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format((float) $item->total_value, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="right">{{ number_format((float) $invoice->subtotal, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>PPN {{ number_format((float) $invoice->tax_rate, 2, ',', '.') }}%</td>
            <td class="right">{{ number_format((float) $invoice->tax_amount, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Total</td>
            <td class="right">{{ number_format((float) $invoice->total, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Dibayar</td>
            <td class="right">{{ number_format((float) $invoice->paid_amount, 2, ',', '.') }}</td>
        </tr>
    </table>

    <div class="footer">
        Dokumen dibuat oleh sistem Mini ERP. Pembayaran dianggap sah setelah dana diterima.
    </div>
</body>
</html>
