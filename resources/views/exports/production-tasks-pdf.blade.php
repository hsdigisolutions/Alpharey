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
    <h1>Tareas de producción / Production tasks — AlphaRey</h1>
    <p class="sub">{{ count($rows) }} tareas / tasks · seguimiento interno (no factura al cliente) / internal tracking (not client billing)</p>

    <table>
        <thead>
            <tr>
                <th>Tarea / Task</th>
                <th>Obra / Project</th>
                <th>Categoría / Category</th>
                <th class="num">Previsto / Planned</th>
                <th class="num">Hecho / Done</th>
                <th class="num">Progreso % / Progress</th>
                <th>Estado / Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ $r['name'] }}</td>
                    <td>{{ $r['project']['name'] ?? '—' }}</td>
                    <td>{{ $r['category'] }}</td>
                    <td class="num">{{ number_format($r['planned_quantity'], 2, ',', '.') }} {{ $r['unit'] }}</td>
                    <td class="num">{{ number_format($r['completed_quantity'], 2, ',', '.') }}</td>
                    <td class="num">{{ $r['progress'] }}%</td>
                    <td>{{ $r['status'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
