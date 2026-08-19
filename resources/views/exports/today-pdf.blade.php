<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Today's Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1A17; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #5C5C56; font-size: 10px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.data th { background: #ECEAE5; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        table.data td { padding: 5px; border-bottom: 1px solid #E2DED8; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Today's Report / Informe de Hoy</h1>
    <p class="muted">{{ $generated_at }}</p>

    <table class="data">
        <thead>
            <tr>
                <th>Worker</th>
                <th>Project</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th class="right">Hours</th>
                <th>Status</th>
                <th class="right">Distance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r['employee'] ?? '' }}</td>
                    <td>{{ $r['project'] ?? '—' }}</td>
                    <td>{{ $r['check_in'] ?? '—' }}</td>
                    <td>{{ $r['check_out'] ?? '—' }}</td>
                    <td class="right">{{ number_format((float) ($r['hours'] ?? 0), 2) }}</td>
                    <td>{{ $r['status'] ?? '' }}</td>
                    <td class="right">{{ $r['distance'] !== null ? round((float) $r['distance']) . ' m' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No rows.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
