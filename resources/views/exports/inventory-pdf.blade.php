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
    </style>
</head>
<body>
    <h1>{{ $title }} — AlphaRey</h1>
    <p class="sub">{{ $generated_at }} · {{ count($rows) }} registros / records</p>

    <table>
        <thead>
            <tr>
                @foreach ($headings as $h)
                    <th>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
