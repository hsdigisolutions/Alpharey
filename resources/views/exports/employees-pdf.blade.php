<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a17; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        p.sub { color: #5c5c56; margin: 0 0 10px; font-size: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #eceae5; padding: 4px 5px; font-size: 8px; text-transform: uppercase; color: #5c5c56; }
        td { padding: 4px 5px; border-bottom: 1px solid #e2ded8; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <h1>Empleados / Employees — Verto5</h1>
    <p class="sub">{{ now()->format('d/m/Y H:i') }} · {{ $employees->count() }} registros / records</p>

    <table>
        <thead>
            <tr>
                <th>Código / Code</th>
                <th>Nombre / Name</th>
                <th>Empresa / Company</th>
                <th>Puesto / Designation</th>
                <th>Ciudad / City</th>
                <th>Tipo / Wage type</th>
                @if ($withWages)
                    <th class="num">Tarifa / Rate</th>
                    <th class="num">Salario / Salary</th>
                @endif
                <th>Activo / Active</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($employees as $employee)
                <tr>
                    <td>{{ $employee->employee_code }}</td>
                    <td>{{ $employee->full_name }}</td>
                    <td>{{ $employee->company?->name }}</td>
                    <td>{{ $employee->designation }}</td>
                    <td>{{ $employee->city }}</td>
                    <td>{{ $employee->wage_type?->value }}</td>
                    @if ($withWages)
                        <td class="num">{{ $employee->getAttribute('wage_rate') }}</td>
                        <td class="num">{{ $employee->getAttribute('base_salary') }}</td>
                    @endif
                    <td>{{ $employee->active ? 'Sí / Yes' : 'No' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
