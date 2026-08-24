@php
    $num = fn ($n) => number_format((float) ($n ?? 0), 2, ',', '.');
    $figures = $report['figures'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Report {{ $module }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1A17; }
        h1 { font-size: 16px; margin: 0 0 2px; text-transform: capitalize; }
        .muted { color: #5C5C56; font-size: 10px; }
        .figures { margin: 12px 0; }
        .figures td { padding: 4px 10px 4px 0; }
        .figures .k { color: #5C5C56; text-transform: uppercase; font-size: 8px; letter-spacing: .04em; }
        .figures .v { font-size: 14px; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data th { background: #ECEAE5; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        table.data td { padding: 5px; border-bottom: 1px solid #E2DED8; }
        .right { text-align: right; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <h1>{{ $module }}</h1>
    <p class="muted">
        {{ $company }} · {{ $filters['from'] ?? '—' }} → {{ $filters['to'] ?? '—' }}
    </p>

    {{-- Figures block: the report's headline numbers --}}
    <table class="figures">
        <tr>
            @foreach ($figures as $key => $value)
                <td>
                    <div class="k">{{ str_replace('_', ' ', $key) }}</div>
                    <div class="v">{{ is_numeric($value) && ! is_int($value) ? $num($value) : $value }}</div>
                </td>
                @if ($loop->iteration % 4 === 0)</tr><tr>@endif
            @endforeach
        </tr>
    </table>

    {{-- Primary table for the module --}}
    @php
        $table = match ($module) {
            'attendance', 'payroll' => $report['by_employee'] ?? [],
            'projects' => $report['hours_per_project'] ?? [],
            // Drop the internal project_id (a drill-down key) from the printout.
            'profitability' => array_map(fn ($r) => Illuminate\Support\Arr::except($r, 'project_id'), $report['rows'] ?? []),
            'commission', 'timesheet', 'deployments' => $report['rows'] ?? [],
            'financial' => $report['unpaid_invoices'] ?? [],
            default => [],
        };
    @endphp

    @if (! empty($table))
        <table class="data">
            <thead>
                <tr>
                    @foreach (array_keys($table[0]) as $col)
                        <th>{{ str_replace('_', ' ', $col) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($table as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ is_bool($cell) ? ($cell ? '✓' : '') : ($cell ?? '—') }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
