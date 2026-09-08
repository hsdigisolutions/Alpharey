@php
    $eur = fn ($n) => number_format((float) ($n ?? 0), 2, ',', '.') . ' €';
    $total = array_sum(array_map(fn ($r) => (float) $r['total'], $rows));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibos / Receipts</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1A1A17; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .sub { color: #5C5C56; font-size: 10px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #ECEAE5; text-align: left; padding: 4px; font-size: 8px; text-transform: uppercase; }
        td { padding: 4px; border-bottom: 1px solid #E2DED8; }
        .right { text-align: right; }
        tr.total td { border-top: 2px solid #1A1A17; font-weight: bold; }
        .receipt { page-break-before: always; }
        .receipt h2 { font-size: 12px; margin: 0 0 1px; }
        .receipt .meta { color: #5C5C56; font-size: 9px; margin: 0 0 8px; }
        .receipt img { max-width: 100%; max-height: 900px; border: 1px solid #E2DED8; }
        .note { color: #8B2020; font-size: 10px; }
        .capped { color: #92620A; font-size: 9px; margin-top: 8px; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <h1>Recibos / Receipts</h1>
    <p class="sub">{{ $label }} — {{ count($rows) }} recibo(s) / receipt(s)</p>
    <table>
        <thead>
            <tr>
                <th>Fecha</th><th>Nº factura</th><th>Proveedor</th><th>Categoría</th>
                <th class="right">Base</th><th class="right">IVA</th><th class="right">Total</th><th>Pago</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d/m/Y') }}</td>
                    <td>{{ $r['number'] ?? '—' }}</td>
                    <td>{{ $r['vendor'] ?? '—' }}</td>
                    <td>{{ $r['category'] ?? '—' }}</td>
                    <td class="right">{{ $eur($r['base']) }}</td>
                    <td class="right">{{ $eur($r['vat']) }}</td>
                    <td class="right">{{ $eur($r['total']) }}</td>
                    <td>{{ $r['payment_method'] ?? '—' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="6" class="right">TOTAL</td>
                <td class="right">{{ $eur($total) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
    @if ($capped)
        <p class="capped">Se muestran los primeros {{ $max }} recibos en el PDF combinado. Usa el ZIP para el conjunto completo. / Showing the first {{ $max }} receipts in the combined PDF — use the ZIP for the full set.</p>
    @endif

    @foreach ($items as $it)
        <div class="receipt">
            <h2>{{ $it['title'] }}</h2>
            <p class="meta">{{ $it['meta'] }}</p>
            @if ($it['unrenderable'])
                <p class="note">No se pudo representar este archivo en el PDF. El original está en el ZIP. / This file could not be rendered here — the original is in the ZIP export.</p>
            @else
                @foreach ($it['images'] as $img)
                    <img src="{{ $img }}" alt="">
                @endforeach
            @endif
        </div>
    @endforeach
</body>
</html>
