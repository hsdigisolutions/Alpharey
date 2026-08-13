<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a17; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        p.sub { color: #5c5c56; margin: 0 0 10px; font-size: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #eceae5; padding: 4px 5px; font-size: 8px; text-transform: uppercase; color: #5c5c56; }
        td { padding: 4px 5px; border-bottom: 1px solid #e2ded8; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <h1>Mediciones / Measurements — AlphaRey</h1>
    <p class="sub">{{ $generated_at }} · {{ $measurements->count() }} registros / records</p>

    <table>
        <thead>
            <tr>
                <th>Fecha / Date</th>
                <th>Obra / Project</th>
                <th>Trabajador / Employee</th>
                <th class="num">Cantidad / Qty</th>
                <th>Unidad / Unit</th>
                <th>Tipo / Type</th>
                <th>Estado / Status</th>
                <th>Motivo / Reason</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($measurements as $m)
                <tr>
                    <td>{{ $m->date->toDateString() }}</td>
                    <td>{{ $m->project?->name }}</td>
                    <td>{{ $m->employee?->full_name ?? '—' }}</td>
                    <td class="num">{{ number_format((float) $m->quantity, 2, ',', '.') }}</td>
                    <td>{{ $m->unit }}</td>
                    <td>{{ $m->measurement_type->value }}</td>
                    <td>{{ $m->status->value }}</td>
                    <td>{{ $m->rejection_reason }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
