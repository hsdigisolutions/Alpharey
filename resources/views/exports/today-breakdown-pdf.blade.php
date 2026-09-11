<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Project Breakdown</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1A17; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #5C5C56; font-size: 10px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.data th { background: #ECEAE5; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        table.data td { padding: 5px; border-bottom: 1px solid #E2DED8; vertical-align: top; }
        .right { text-align: right; }
        /* Keep the numbers tight and let Employees take the slack (readable share). */
        col.num { width: 52px; }
        col.proj { width: 120px; }
        td.emp { font-size: 9.5px; line-height: 1.4; }
        tr.totals td { border-top: 2px solid #C8C4BC; border-bottom: none; font-weight: bold; background: #F5F4F0; }
        .sub { color: #5C5C56; font-weight: normal; font-size: 9px; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <h1>Project Breakdown / Desglose por obra</h1>
    <p class="muted">{{ $generated_at }}</p>

    <table class="data">
        <colgroup>
            <col class="proj"><col><col class="num"><col class="num"><col class="num"><col class="num">
        </colgroup>
        <thead>
            <tr>
                <th>Project</th>
                <th>Employees (days)</th>
                <th class="right">Assigned</th>
                <th class="right">Present</th>
                <th class="right">Absent</th>
                <th class="right">Hours</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r['project'] ?? 'Sin proyecto / No project' }}</td>
                    <td class="emp">
                        @foreach (($r['employees'] ?? []) as $e){{ $loop->first ? '' : ', ' }}{{ $e['name'] ?? '?' }} ({{ (int) ($e['days'] ?? 0) }})@endforeach
                    </td>
                    <td class="right">{{ (int) ($r['assigned'] ?? 0) }}</td>
                    <td class="right">{{ (int) ($r['present'] ?? 0) }}</td>
                    <td class="right">{{ (int) ($r['absent'] ?? 0) }}</td>
                    <td class="right">{{ number_format((float) ($r['hours'] ?? 0), 2) }}h</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No rows.</td></tr>
            @endforelse

            @if (!empty($rows))
                <tr class="totals">
                    <td>TOTAL · {{ (int) ($totals['projects'] ?? 0) }} {{ 'proyectos / projects' }}</td>
                    <td>{{ (int) ($totals['unique_employees'] ?? 0) }} <span class="sub">únicos/unique</span> · {{ (int) ($totals['employee_instances'] ?? 0) }} <span class="sub">asignaciones/instances</span></td>
                    <td class="right">{{ (int) ($totals['assigned'] ?? 0) }}</td>
                    <td class="right">{{ (int) ($totals['present'] ?? 0) }}</td>
                    <td class="right">{{ (int) ($totals['absent'] ?? 0) }}</td>
                    <td class="right">{{ number_format((float) ($totals['hours'] ?? 0), 2) }}h</td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
