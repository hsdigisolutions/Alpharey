<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Parte de horas — {{ $project }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1A17; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        h2 { font-size: 11px; margin: 18px 0 4px; text-transform: uppercase; letter-spacing: .05em; color: #5C5C56; }
        .muted { color: #5C5C56; font-size: 10px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.data th { background: #ECEAE5; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        table.data td { padding: 5px; border-bottom: 1px solid #E2DED8; }
        .right { text-align: right; }
        .tot { margin-top: 8px; font-weight: bold; }
        .worker-head { margin: 14px 0 0; padding: 5px 6px; background: #F5E6D8; font-weight: bold; font-size: 10px; }
        table.detail { width: 100%; border-collapse: collapse; }
        table.detail th { background: #F5F4F0; text-align: left; padding: 4px 6px; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #5C5C56; }
        table.detail td { padding: 4px 6px; border-bottom: 1px solid #ECEAE5; }
        .avoid-break { page-break-inside: avoid; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <h1>Parte de horas por proyecto / Project Timesheet</h1>
    <p class="muted">Proyecto / Project: <strong>{{ $project }}</strong> · Periodo / Period: {{ $start }} – {{ $end }}</p>

    {{-- Summary --}}
    <h2>Resumen / Summary</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Trabajador / Worker</th>
                <th>Designación / Designation</th>
                <th class="right">Días / Days</th>
                <th class="right">Horas / Hours</th>
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
    <p class="tot">TOTAL: {{ $sheet['workers'] }} trabajadores / workers · {{ $sheet['total_days'] }} días / days · {{ number_format((float) $sheet['total_hours'], 2) }} h</p>

    {{-- Per-worker detail --}}
    <h2>Detalle / Detail</h2>
    @foreach ($sheet['rows'] as $r)
        <div class="avoid-break">
            <p class="worker-head">
                {{ $r['employee'] ?? '' }} — {{ $r['designation'] ?? '—' }} — {{ $r['days_present'] }} días / days · {{ number_format((float) $r['hours'], 2) }} h
            </p>
            <table class="detail">
                <thead>
                    <tr>
                        <th style="width:22%">Fecha / Date</th>
                        <th style="width:12%">Día / Day</th>
                        <th style="width:46%">Tipo de jornada / Day type</th>
                        <th class="right" style="width:20%">Horas / Hours</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($r['days'] as $d)
                        <tr>
                            <td>{{ $d['date_fmt'] }}</td>
                            <td>{{ $d['weekday'] }}</td>
                            <td>{{ $d['day_type_label'] }}</td>
                            <td class="right">{{ number_format((float) $d['hours'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</body>
</html>
