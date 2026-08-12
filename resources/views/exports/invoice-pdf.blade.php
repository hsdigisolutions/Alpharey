@php
    $eur = fn ($n) => number_format((float) ($n ?? 0), 2, ',', '.') . ' €';
    $party = $invoice->type->value === 'sale' ? $invoice->client : $invoice->vendor;
    $logo = $logo ?? null;
    $co = $invoice->company;
    $cityLine = trim(collect([$co?->postal_code, $co?->city])->filter()->implode(' '));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura {{ $invoice->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1A1A17; }
        h1 { font-size: 18px; margin: 0; }
        .muted { color: #5C5C56; }
        .right { text-align: right; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.items th { background: #ECEAE5; text-align: left; padding: 6px; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        table.items td { padding: 6px; border-bottom: 1px solid #E2DED8; }
        table.totals { width: 45%; margin-left: 55%; margin-top: 14px; border-collapse: collapse; }
        table.totals td { padding: 3px 0; }
        table.totals tr.grand td { border-top: 2px solid #1A1A17; font-weight: bold; font-size: 13px; padding-top: 6px; }
        .head { width: 100%; }
        .head td { vertical-align: top; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                @if ($logo)
                    <img src="{{ $logo }}" alt="" style="max-height:52px; max-width:180px; margin-bottom:6px;"><br>
                @endif
                <h1>{{ $co?->name }}</h1>
                @if ($co?->cif)
                    <div class="muted">CIF: {{ $co->cif }}</div>
                @endif
                @if ($co?->address)
                    <div class="muted">{{ $co->address }}</div>
                @endif
                @if ($cityLine !== '')
                    <div class="muted">{{ $cityLine }}</div>
                @endif
                @if ($co?->province)
                    <div class="muted">{{ $co->province }}</div>
                @endif
            </td>
            <td class="right">
                <h1>{{ $invoice->number }}</h1>
                <div class="muted">
                    {{ $invoice->sub_type?->value === 'pre' ? 'Proforma / Pre-invoice' : 'Factura / Invoice' }}<br>
                    Fecha: {{ $invoice->invoice_date->format('d/m/Y') }}
                    @if ($invoice->due_date)
                        <br>Vencimiento: {{ $invoice->due_date->format('d/m/Y') }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <p style="margin-top:18px">
        <strong>{{ $party?->name }}</strong>
        @if ($invoice->project)
            <br><span class="muted">Obra: {{ $invoice->project->name }}</span>
        @endif
    </p>

    <table class="items">
        <thead>
            <tr>
                <th>Descripción / Description</th>
                <th class="right">Cant.</th>
                <th class="right">Precio</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lineItems as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $line->quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="right">{{ $eur($line->unit_price) }}</td>
                    <td class="right">{{ $eur($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="right">{{ $eur($invoice->subtotal) }}</td>
        </tr>
        @if ((float) $invoice->discount_amount > 0)
            <tr>
                <td>Descuento</td>
                <td class="right">− {{ $eur($invoice->discount_amount) }}</td>
            </tr>
        @endif
        {{-- Blank VAT means NO VAT line at all — never print "0%" (DECISIONS.md) --}}
        @if ($invoice->vat_rate)
            <tr>
                <td>IVA {{ rtrim(rtrim(number_format($invoice->vat_rate->effectivePercent($invoice->vat_custom_percent), 2, ',', '.'), '0'), ',') }}%</td>
                <td class="right">{{ $eur($invoice->vat_amount) }}</td>
            </tr>
        @endif
        @if ((float) $invoice->retention_amount > 0)
            <tr>
                <td>Retención {{ rtrim(rtrim(number_format((float) $invoice->retention_percent, 2, ',', '.'), '0'), ',') }}%</td>
                <td class="right">− {{ $eur($invoice->retention_amount) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>TOTAL</td>
            <td class="right">{{ $eur($invoice->total) }}</td>
        </tr>
    </table>

    @if ($invoice->notes)
        <p class="muted" style="margin-top:24px">{{ $invoice->notes }}</p>
    @endif
</body>
</html>
