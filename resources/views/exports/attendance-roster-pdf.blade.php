<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Asistencia — {{ $panel['project']['name'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1A17; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #5C5C56; font-size: 10px; }
        .tally { float: right; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.data th { background: #ECEAE5; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        table.data td { padding: 5px; border-bottom: 1px solid #E2DED8; }
        .center { text-align: center; }
        .right { text-align: right; }
        .st-present, .st-working { color: #2D6A4F; }
        .st-absent { color: #8B2020; }
        .st-late, .st-early_leave { color: #92620A; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <span class="tally">{{ $panel['present'] }} / {{ $panel['assigned'] }} presentes / present</span>
    <h1>{{ $panel['project']['name'] }}</h1>
    <p class="muted">Asistencia por proyecto / Project attendance · {{ $panel['date'] }}</p>

    <table class="data">
        <thead>
            <tr>
                <th>Trabajador / Worker</th>
                <th>Designación</th>
                <th class="center">Entrada / In</th>
                <th class="center">Salida / Out</th>
                <th class="right">Horas / Hours</th>
                <th>Estado / Status</th>
                <th class="right">Distancia</th>
            </tr>
        </thead>
        <tbody>
            @php
                $labels = ['present'=>'Presente','working'=>'Trabajando','absent'=>'Ausente','late'=>'Tarde','early_leave'=>'Salida anticipada','leave'=>'Permiso'];
            @endphp
            @forelse ($panel['rows'] as $r)
                <tr>
                    <td>{{ $r['employee'] ?? '' }}</td>
                    <td>{{ $r['designation'] ?? '—' }}</td>
                    <td class="center">{{ $r['check_in'] ?? '—' }}</td>
                    <td class="center">{{ $r['check_out'] ?? '—' }}</td>
                    <td class="right">{{ $r['hours'] !== null ? number_format((float) $r['hours'], 2) : '—' }}</td>
                    <td class="st-{{ $r['status'] }}">{{ $labels[$r['status']] ?? $r['status'] }}</td>
                    <td class="right">{{ $r['distance'] !== null ? round((float) $r['distance']) . ' m' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No hay trabajadores asignados a este proyecto.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
