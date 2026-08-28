<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Projects with no activity today</title>
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
    @include('exports.partials.logo-header')
    <h1>Projects with no activity / Proyectos sin actividad</h1>
    <p class="muted">{{ $generated_at }}</p>

    <table class="data">
        <thead>
            <tr>
                <th>Project</th>
                <th class="right">Assigned Workers</th>
                <th>Last Activity</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r['project'] ?? '' }}</td>
                    <td class="right">{{ (int) ($r['assigned'] ?? 0) }}</td>
                    <td>{{ $r['last_activity'] !== null ? $r['last_activity'] : 'Never' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">No rows.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
