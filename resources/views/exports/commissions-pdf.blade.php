@php
    $eur = fn ($n) => number_format((float) ($n ?? 0), 2, ',', '.') . ' €';
    $total = $entries->sum(fn ($e) => $e->payableAmount());
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comisiones {{ $month }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1A17; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #5C5C56; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th { background: #ECEAE5; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        td { padding: 5px; border-bottom: 1px solid #E2DED8; }
        .right { text-align: right; }
        tr.total td { border-top: 2px solid #1A1A17; font-weight: bold; font-size: 12px; }
    </style>
</head>
<body>
    <h1>Comisiones / Commissions — {{ $month }}</h1>

    <table>
        <thead>
            <tr>
                <th>Empleado</th>
                <th>Obra</th>
                <th>Factura</th>
                <th class="right">%</th>
                <th class="right">Base</th>
                <th class="right">Original</th>
                <th class="right">Ajustada</th>
                <th>Motivo</th>
                <th class="right">A pagar</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $e)
                <tr>
                    <td>{{ $e->employee?->full_name }}</td>
                    <td>{{ $e->project?->name ?? '—' }}</td>
                    <td>{{ $e->invoice?->number ?? '—' }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $e->commission_percent, 2, ',', '.'), '0'), ',') }}%</td>
                    <td class="right">{{ $eur($e->base_amount) }}</td>
                    <td class="right">{{ $eur($e->original_amount) }}</td>
                    <td class="right">{{ $e->adjusted_amount !== null ? $eur($e->adjusted_amount) : '—' }}</td>
                    <td class="muted">{{ $e->adjustment_reason ?? '' }}</td>
                    <td class="right"><strong>{{ $eur($e->payableAmount()) }}</strong></td>
                    <td>{{ $e->status->value }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="8">TOTAL</td>
                <td class="right">{{ $eur($total) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
