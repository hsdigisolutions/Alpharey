@php
    $eur = fn ($n) => number_format((float) ($n ?? 0), 2, ',', '.') . ' €';
    $total = $expenses->sum(fn ($e) => (float) $e->total);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Gastos / Expenses</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1A17; }
        h1 { font-size: 16px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #ECEAE5; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; letter-spacing: .03em; }
        td { padding: 5px; border-bottom: 1px solid #E2DED8; }
        .right { text-align: right; }
        tr.total td { border-top: 2px solid #1A1A17; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Gastos / Expenses</h1>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Número</th>
                <th>Tipo</th>
                <th>Proveedor</th>
                <th>Obra</th>
                <th>A cargo de</th>
                <th class="right">Base</th>
                <th class="right">IVA</th>
                <th class="right">Total</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($expenses as $e)
                <tr>
                    <td>{{ $e->date->format('d/m/Y') }}</td>
                    <td>{{ $e->number ?? '—' }}</td>
                    <td>{{ $e->type->value }}</td>
                    <td>{{ $e->vendor?->name ?? '—' }}</td>
                    <td>{{ $e->project?->name ?? '—' }}</td>
                    <td>{{ $e->bearable_by->value }}</td>
                    <td class="right">{{ $eur($e->subtotal) }}</td>
                    <td class="right">{{ $e->vat_rate ? $eur($e->vat_amount) : '—' }}</td>
                    <td class="right">{{ $eur($e->total) }}</td>
                    <td>{{ $e->approved ? 'Aprobado' : 'Pendiente' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="8" class="right">TOTAL</td>
                <td class="right">{{ $eur($total) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
