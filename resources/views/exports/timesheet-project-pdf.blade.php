<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Timesheet — project</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1A17; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #5C5C56; font-size: 10px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.data th { background: #ECEAE5; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        table.data td { padding: 5px; border-bottom: 1px solid #E2DED8; }
        .right { text-align: right; }
        .tot { margin-top: 10px; font-weight: bold; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <h1>{{ $project }}</h1>
    <p class="muted">{{ $start }} → {{ $end }}</p>

    <table class="data">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Designation</th>
                <th class="right">Days present</th>
                <th class="right">Hours</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sheet['rows'] as $r)
                <tr>
                    <td>{{ $r['employee'] ?? '' }}</td>
                    <td>{{ $r['designation'] ?? '—' }}</td>
                    <td class="right">{{ $r['days_present'] }}</td>
                    <td class="right">{{ number_format((float) $r['hours'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="tot">Workers: {{ $sheet['workers'] }} · Total hours: {{ number_format((float) $sheet['total_hours'], 2) }}</p>
</body>
</html>
