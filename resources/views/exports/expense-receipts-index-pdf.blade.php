@php
    $eur = fn ($n) => number_format((float) ($n ?? 0), 2, ',', '.') . ' €';
    $base = array_sum(array_map(fn ($r) => (float) $r['base'], $rows));
    $vat = array_sum(array_map(fn ($r) => (float) $r['vat'], $rows));
    $total = array_sum(array_map(fn ($r) => (float) $r['total'], $rows));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Índice de recibos / Receipts index</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1A1A17; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .sub { color: #5C5C56; font-size: 10px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #ECEAE5; text-align: left; padding: 4px; font-size: 8px; text-transform: uppercase; letter-spacing: .03em; }
        td { padding: 4px; border-bottom: 1px solid #E2DED8; vertical-align: top; }
        .right { text-align: right; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 7.5px; color: #5C5C56; }
        tr.total td { border-top: 2px solid #1A1A17; font-weight: bold; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <h1>Índice de recibos / Receipts index</h1>
    <p class="sub">{{ $label }} — {{ count($rows) }} recibo(s) / receipt(s)</p>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Nº factura</th>
                <th>Proveedor</th>
                <th>Obra</th>
                <th>Categoría</th>
                <th class="right">Base</th>
                <th class="right">IVA</th>
                <th class="right">Total</th>
                <th>Pago</th>
                <th>Archivo</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d/m/Y') }}</td>
                    <td>{{ $r['number'] ?? '—' }}</td>
                    <td>{{ $r['vendor'] ?? '—' }}</td>
                    <td>{{ $r['project'] ?? '—' }}</td>
                    <td>{{ $r['category'] ?? '—' }}</td>
                    <td class="right">{{ $eur($r['base']) }}</td>
                    <td class="right">{{ $eur($r['vat']) }}</td>
                    <td class="right">{{ $eur($r['total']) }}</td>
                    <td>{{ $r['payment_method'] ?? '—' }}</td>
                    <td class="mono">{{ $r['filename'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="5" class="right">TOTAL</td>
                <td class="right">{{ $eur($base) }}</td>
                <td class="right">{{ $eur($vat) }}</td>
                <td class="right">{{ $eur($total) }}</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
