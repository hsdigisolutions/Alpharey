<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a17; }
        h1 { font-size: 18px; margin: 0; }
        .muted { color: #5c5c56; }
        .head { border-bottom: 2px solid #d4956a; padding-bottom: 8px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { text-align: left; background: #eceae5; padding: 6px; font-size: 9px; text-transform: uppercase; }
        td { padding: 6px; border-bottom: 1px solid #e2ded8; }
        .num { text-align: right; }
        .totals { margin-top: 12px; width: 40%; float: right; }
        .totals td { border: 0; padding: 3px 6px; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <div class="head">
        <h1>Propuesta / Proposal {{ $proposal->number }}</h1>
        <p class="muted">AlphaRey · {{ $proposal->proposal_date?->format('d/m/Y') }}
            @if ($proposal->expiry_date) · Válida hasta / Valid until {{ $proposal->expiry_date->format('d/m/Y') }} @endif
        </p>
    </div>

    <p><strong>Cliente / Client:</strong> {{ $proposal->client?->name ?? '—' }}</p>
    @if ($proposal->project)
        <p><strong>Proyecto / Project:</strong> {{ $proposal->project->name }}</p>
    @endif
    @if ($proposal->description)
        <p>{{ $proposal->description }}</p>
    @endif

    @if (!empty($proposal->line_items))
        <table>
            <thead>
                <tr>
                    <th>Descripción / Description</th>
                    <th class="num">Cant. / Qty</th>
                    <th class="num">Precio / Unit price</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($proposal->line_items as $item)
                    <tr>
                        <td>{{ $item['description'] ?? '' }}</td>
                        <td class="num">{{ number_format((float) ($item['qty'] ?? 0), 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) ($item['unit_price'] ?? 0), 2, ',', '.') }} €</td>
                        <td class="num">{{ number_format((float) ($item['qty'] ?? 0) * (float) ($item['unit_price'] ?? 0), 2, ',', '.') }} €</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ number_format((float) $proposal->subtotal, 2, ',', '.') }} €</td></tr>
        <tr>
            <td>IVA / VAT {{ $proposal->vat_rate ? rtrim(rtrim(number_format($proposal->vat_rate->effectivePercent($proposal->vat_custom_percent), 2, ',', '.'), '0'), ',').'%' : 'No aplica' }}</td>
            <td class="num">{{ $proposal->vat_amount !== null ? number_format((float) $proposal->vat_amount, 2, ',', '.').' €' : '—' }}</td>
        </tr>
        <tr><td><strong>Total</strong></td><td class="num"><strong>{{ number_format((float) $proposal->total_amount, 2, ',', '.') }} €</strong></td></tr>
    </table>
</body>
</html>
