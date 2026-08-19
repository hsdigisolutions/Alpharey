@php
    /**
     * Every payslip for the month, one per page (spec Screen 12:
     * "Export PDF (all payslips)"). Same internal-management caveat as the
     * single payslip â€” this is NOT an official nÃ³mina.
     */
    $eur = fn ($n) => number_format((float) ($n ?? 0), 2, ',', '.') . ' â‚¬';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>NÃ³minas {{ $month }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1A1A17; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #5C5C56; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        td { padding: 3px 0; }
        td.amount { text-align: right; }
        .rule td { border-top: 1px solid #C8C4BC; }
        .total td { border-top: 2px solid #1A1A17; font-weight: bold; font-size: 13px; }
        .note { margin-top: 8px; padding: 5px 7px; background: #D8EAF5; color: #1A4E6B; font-size: 10px; }
        .foot { margin-top: 20px; font-size: 9px; color: #9C9A92; }
    </style>
</head>
<body>
@forelse ($payrolls as $payroll)
    <div class="page">
        @if (!empty($logo))
            <img src="{{ $logo }}" alt="" style="max-height:44px; max-width:160px; margin-bottom:5px;"><br>
        @endif
        <h1>{{ $payroll->company?->displayName() }}</h1>
        @if ($payroll->company?->cif || $payroll->company?->address)
            <div class="muted" style="font-size:10px;">{{ $payroll->company?->address }}@if ($payroll->company?->address && $payroll->company?->cif) &middot; @endif @if ($payroll->company?->cif) CIF: {{ $payroll->company?->cif }} @endif</div>
        @endif
        <div class="muted">NÃ³mina interna / Internal payslip â€” <strong>{{ $payroll->month }}</strong></div>

        <table>
            <tr>
                <td><strong>{{ $payroll->employee?->full_name }}</strong></td>
                <td class="amount muted">{{ $payroll->employee?->designation }}</td>
            </tr>
        </table>

        @foreach ($payroll->deployment_notes ?? [] as $note)
            <div class="note">{{ $note }}</div>
        @endforeach

        @php $periods = $payroll->ratePeriodsSafe() ?? []; @endphp
        @if (count($periods) > 1)
            @php
                $unit = fn ($t) => $t === 'daily' ? '/dÃ­a' : ($t === 'hourly' ? '/hora' : '');
                $fmtDate = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d/m/Y');
            @endphp
            <table>
                <tr><td colspan="2"><strong>PerÃ­odos de tarifa</strong></td></tr>
                @foreach ($periods as $i => $p)
                    <tr>
                        <td>
                            PerÃ­odo {{ $i + 1 }}: {{ $fmtDate($p['from']) }} â†’ {{ $fmtDate($p['to']) }}
                            <span class="muted">
                                ({{ $eur($p['rate']) }}{{ $unit($p['wage_type']) }} Â·
                                {{ $p['wage_type'] === 'hourly' ? ((float) $p['hours']).' h' : ((int) $p['days']).' dÃ­as' }})
                            </span>
                        </td>
                        <td class="amount">{{ $eur($p['amount']) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @php
            $daySummary = $payroll->dayTypeSummarySafe() ?? [];
            $dtLabel = ['full' => 'Jornadas completas', 'half' => 'Medias jornadas', 'hourly' => 'Por horas', 'per_meter' => 'Por metros'];
            $dtUnit = ['full' => 'dÃ­as', 'half' => 'dÃ­as', 'hourly' => 'h', 'per_meter' => 'm'];
            $num = fn ($n) => ((float) $n == (int) $n) ? (string) (int) $n : number_format((float) $n, 2, ',', '.');
            $labelFor = function ($s) use ($dtLabel) {
                if (! empty($s['weekend'])) {
                    return in_array($s['type'], ['full', 'half']) ? 'DÃ­as fin de semana' : ($dtLabel[$s['type']] ?? $s['type']).' (FS)';
                }
                return $dtLabel[$s['type']] ?? $s['type'];
            };
        @endphp
        <table>
            <tr><td>Salario base / Sueldo</td><td class="amount">{{ $eur($payroll->base_salary) }}</td></tr>
            @if (count($daySummary))
                @foreach ($daySummary as $s)
                    <tr>
                        <td>{{ $labelFor($s) }}
                            <span class="muted">({{ $num($s['units']) }} {{ $dtUnit[$s['type']] ?? '' }} Ã— {{ $eur($s['rate']) }})</span>
                        </td>
                        <td class="amount">{{ $eur($s['amount']) }}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td>DÃ­as trabajados <span class="muted">({{ (float) $payroll->attendance_days }})</span></td>
                    <td class="amount">{{ $eur($payroll->days_amount) }}</td>
                </tr>
                <tr>
                    <td>Horas trabajadas <span class="muted">({{ (float) $payroll->attendance_hours }} h)</span></td>
                    <td class="amount">{{ $eur($payroll->hours_amount) }}</td>
                </tr>
            @endif
            <tr><td>Reembolsos</td><td class="amount">{{ $eur($payroll->reimbursements) }}</td></tr>
            <tr><td>Gastos de obra del trabajador</td><td class="amount">{{ $eur($payroll->project_expenses) }}</td></tr>
            <tr>
                <td>Horas extra <span class="muted">({{ (float) $payroll->overtime_hours }} h)</span></td>
                <td class="amount">{{ $eur($payroll->overtime_pay) }}</td>
            </tr>
            <tr class="rule">
                <td><strong>Salario bruto / Gross pay</strong></td>
                <td class="amount"><strong>{{ $eur($payroll->gross_pay) }}</strong></td>
            </tr>
            <tr><td>Anticipos</td><td class="amount">âˆ’ {{ $eur($payroll->advance_deductions) }}</td></tr>
            <tr><td>Otras deducciones</td><td class="amount">âˆ’ {{ $eur($payroll->other_deductions) }}</td></tr>
            <tr><td>AÃ±adidos manuales</td><td class="amount">+ {{ $eur($payroll->manual_additions) }}</td></tr>
            <tr class="total">
                <td>NETO A PAGAR / NET PAY</td>
                <td class="amount">{{ $eur($payroll->net_amount) }}</td>
            </tr>
        </table>

        <p class="foot">
            Documento interno de gestiÃ³n. No sustituye a la nÃ³mina oficial; el IRPF y las
            cotizaciones a la Seguridad Social los calcula la gestorÃ­a. /
            Internal management document â€” not an official payslip.
        </p>
    </div>
@empty
    <p class="muted">Sin nÃ³minas para {{ $month }}. / No payrolls for {{ $month }}.</p>
@endforelse
</body>
</html>
