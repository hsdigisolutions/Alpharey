@php
    /**
     * Internal management payslip (DECISIONS.md): hours, rates, deductions and
     * net pay for management visibility. This is NOT an official nómina — IRPF
     * and Seguridad Social contributions stay with the gestoría and are never
     * calculated here.
     */
    $eur = fn ($n) => number_format((float) ($n ?? 0), 2, ',', '.') . ' €';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Nómina {{ $payroll->month }} — {{ $payroll->employee?->full_name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1A1A17; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #5C5C56; }
        .accent { color: #D4956A; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        td { padding: 4px 0; }
        td.amount { text-align: right; }
        .rule td { border-top: 1px solid #C8C4BC; }
        .total td { border-top: 2px solid #1A1A17; font-weight: bold; font-size: 13px; }
        .note { margin-top: 10px; padding: 6px 8px; background: #D8EAF5; color: #1A4E6B; font-size: 10px; }
        .foot { margin-top: 24px; font-size: 9px; color: #9C9A92; }
    </style>
</head>
<body>
    <h1>{{ $payroll->company?->name }}</h1>
    <div class="muted">
        Nómina interna / Internal payslip — <strong>{{ $payroll->month }}</strong>
    </div>

    <table>
        <tr>
            <td><strong>{{ $payroll->employee?->full_name }}</strong></td>
            <td class="amount muted">{{ $payroll->employee?->designation }}</td>
        </tr>
    </table>

    @foreach ($payroll->deployment_notes ?? [] as $note)
        <div class="note">{{ $note }}</div>
    @endforeach

    @php $periods = $payroll->rate_periods ?? []; @endphp
    @if (count($periods) > 1)
        @php
            $unit = fn ($t) => $t === 'daily' ? '/día' : ($t === 'hourly' ? '/hora' : '');
            $fmtDate = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d/m/Y');
        @endphp
        <table>
            <tr><td colspan="2"><strong>Períodos de tarifa</strong></td></tr>
            @foreach ($periods as $i => $p)
                <tr>
                    <td>
                        Período {{ $i + 1 }}:
                        {{ $fmtDate($p['from']) }} → {{ $fmtDate($p['to']) }}
                        <span class="muted">
                            ({{ $eur($p['rate']) }}{{ $unit($p['wage_type']) }} ·
                            {{ $p['wage_type'] === 'hourly' ? ((float) $p['hours']).' h' : ((int) $p['days']).' días' }})
                        </span>
                    </td>
                    <td class="amount">{{ $eur($p['amount']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <table>
        <tr>
            <td>Salario base / Sueldo</td>
            <td class="amount">{{ $eur($payroll->base_salary) }}</td>
        </tr>
        <tr>
            <td>Días trabajados <span class="muted">({{ (float) $payroll->attendance_days }})</span></td>
            <td class="amount">{{ $eur($payroll->days_amount) }}</td>
        </tr>
        <tr>
            <td>Horas trabajadas <span class="muted">({{ (float) $payroll->attendance_hours }} h)</span></td>
            <td class="amount">{{ $eur($payroll->hours_amount) }}</td>
        </tr>
        <tr>
            <td>Reembolsos</td>
            <td class="amount">{{ $eur($payroll->reimbursements) }}</td>
        </tr>
        <tr>
            <td>Gastos de obra del trabajador</td>
            <td class="amount">{{ $eur($payroll->project_expenses) }}</td>
        </tr>
        <tr>
            <td>Horas extra <span class="muted">({{ (float) $payroll->overtime_hours }} h)</span></td>
            <td class="amount">{{ $eur($payroll->overtime_pay) }}</td>
        </tr>
        <tr class="rule">
            <td><strong>Salario bruto / Gross pay</strong></td>
            <td class="amount"><strong>{{ $eur($payroll->gross_pay) }}</strong></td>
        </tr>
        <tr>
            <td>Anticipos</td>
            <td class="amount">− {{ $eur($payroll->advance_deductions) }}</td>
        </tr>
        @if((float)($payroll->fine_deductions ?? 0) > 0)
        <tr>
            <td>Multas de vehículo</td>
            <td class="amount">− {{ $eur($payroll->fine_deductions) }}</td>
        </tr>
        @endif
        <tr>
            <td>Otras deducciones</td>
            <td class="amount">− {{ $eur($payroll->other_deductions) }}</td>
        </tr>
        <tr>
            <td>Añadidos manuales</td>
            <td class="amount">+ {{ $eur($payroll->manual_additions) }}</td>
        </tr>
        <tr class="total">
            <td>NETO A PAGAR / NET PAY</td>
            <td class="amount">{{ $eur($payroll->net_amount) }}</td>
        </tr>
    </table>

    @if ($payroll->notes)
        <p class="muted" style="margin-top:12px">{{ $payroll->notes }}</p>
    @endif

    <p class="foot">
        Documento interno de gestión. No sustituye a la nómina oficial; el IRPF y las
        cotizaciones a la Seguridad Social los calcula la gestoría. /
        Internal management document — not an official payslip.
    </p>
</body>
</html>
