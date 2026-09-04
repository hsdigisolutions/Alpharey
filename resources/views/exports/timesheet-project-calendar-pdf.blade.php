<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Parte de horas (calendario) — {{ $project }}</title>
    <style>
        @page { margin: 14px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1A1A17; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .muted { color: #5C5C56; font-size: 9px; margin: 0 0 8px; }
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid th, table.grid td { border: 0.5px solid #E2DED8; text-align: center; }
        /* Worker column */
        th.emp, td.emp { text-align: left; width: 118px; padding: 3px 5px; }
        td.emp .name { font-weight: bold; font-size: 9px; }
        td.emp .desig { color: #9C9A92; font-size: 7px; }
        /* Day columns */
        th.day { padding: 2px 0; font-size: 7px; line-height: 1.15; }
        th.day .num { font-size: 9px; font-weight: bold; }
        td.cell { padding: 2px 0; height: 15px; }
        /* Total columns */
        th.tot, td.tot { width: 30px; padding: 3px 2px; }
        td.tot .d { font-weight: bold; font-size: 9px; }
        td.tot .h { color: #C4845A; font-size: 8px; }
        thead th { background: #ECEAE5; color: #5C5C56; }
        .weekend { background: #F1EFEA; }
        .mark { display: inline-block; min-width: 13px; padding: 1px 2px; border-radius: 3px; font-size: 8px; font-weight: bold; }
        .m-full { background: #D8F0E4; color: #2D6A4F; }
        .m-half { background: #FEF3CD; color: #92620A; }
        .m-hours { background: #D8EAF5; color: #1A4E6B; }
        tfoot th, tfoot td { background: #ECEAE5; font-size: 8px; }
        tfoot .lbl { text-align: left; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; color: #5C5C56; padding: 3px 5px; }
        tfoot .h { color: #C4845A; }
        tfoot .grand { background: #F5E6D8; color: #C4845A; font-weight: bold; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <h1>Parte de horas — Calendario / Project Timesheet — Calendar</h1>
    <p class="muted">Proyecto / Project: <strong>{{ $project }}</strong> · Periodo / Period: {{ $start }} – {{ $end }}
        · Trabajadores / Workers: {{ count($calendar['rows']) }} · Total: {{ number_format($calendar['grand_hours'], 2) }} h</p>

    @php
        // Mark → colour class, mirroring the on-screen grid tile.
        $markClass = fn (string $m): string => $m === 'F' ? 'm-full' : ($m === 'H' ? 'm-half' : 'm-hours');
        $fmtHours = fn ($h): string => (float) $h == (float) (int) $h ? (string) (int) $h : number_format((float) $h, 1);
    @endphp

    <table class="grid">
        <thead>
            <tr>
                <th class="emp">Trabajador / Worker</th>
                @foreach ($calendar['day_cols'] as $d)
                    <th class="day {{ $d['weekend'] ? 'weekend' : '' }}">
                        <span class="num">{{ $d['day'] }}</span><br>{{ $d['weekday'] }}
                    </th>
                @endforeach
                <th class="tot">Días</th>
                <th class="tot">Horas</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($calendar['rows'] as $r)
                <tr>
                    <td class="emp">
                        <div class="name">{{ $r['employee'] }}</div>
                        @if (!empty($r['designation']) && $r['designation'] !== '—')
                            <div class="desig">{{ $r['designation'] }}</div>
                        @endif
                    </td>
                    @foreach ($calendar['day_cols'] as $d)
                        @php $mark = $r['marks'][$d['date']] ?? ''; @endphp
                        <td class="cell {{ $d['weekend'] && $mark === '' ? 'weekend' : '' }}">
                            @if ($mark !== '')
                                <span class="mark {{ $markClass($mark) }}">{{ $mark }}</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="tot">
                        <div class="d">{{ $r['days_present'] }}</div>
                        <div class="h">{{ $fmtHours($r['hours']) }}h</div>
                    </td>
                </tr>
            @empty
                <tr><td class="emp" colspan="{{ count($calendar['day_cols']) + 3 }}">—</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th class="lbl">Trabajadores/día / Workers/day</th>
                @foreach ($calendar['day_cols'] as $d)
                    <td class="{{ $d['weekend'] ? 'weekend' : '' }}">{{ $calendar['daily_present'][$d['date']] ?? 0 }}</td>
                @endforeach
                <td class="grand">{{ $calendar['grand_days'] }}</td>
                <td class="grand"></td>
            </tr>
            <tr>
                <th class="lbl">Horas/día / Hours/day</th>
                @foreach ($calendar['day_cols'] as $d)
                    <td class="h {{ $d['weekend'] ? 'weekend' : '' }}">{{ ($calendar['daily_hours'][$d['date']] ?? 0) > 0 ? $fmtHours($calendar['daily_hours'][$d['date']]) : '' }}</td>
                @endforeach
                <td class="grand"></td>
                <td class="grand">{{ $fmtHours($calendar['grand_hours']) }}h</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
