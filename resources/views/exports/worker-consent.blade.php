<!DOCTYPE html>
<html lang="{{ $consent->language }}">
<head>
    <meta charset="utf-8">
    <title>Consent record #{{ $consent->id }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a17; font-size: 12px; line-height: 1.45; margin: 32px; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .sub { color: #5c5c56; font-size: 11px; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        td { padding: 5px 8px; border: 1px solid #e2ded8; vertical-align: top; }
        td.k { width: 34%; background: #f5f4f0; color: #5c5c56; font-weight: bold; }
        .badge { display: inline-block; padding: 1px 7px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .yes { background: #d8f0e4; color: #2d6a4f; }
        .no { background: #ecEae5; color: #6b6963; }
        .rev { background: #fdeaea; color: #8b2020; }
        h2 { font-size: 12px; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: 0.04em; color: #5c5c56; }
        .notice { white-space: pre-wrap; border: 1px solid #e2ded8; background: #faf9f7; padding: 12px; font-size: 11px; }
        .foot { margin-top: 20px; font-size: 10px; color: #9c9a92; }
    </style>
</head>
<body>
    @include('exports.partials.logo-header')
    <h1>Registro de consentimiento / Consent record</h1>
    <div class="sub">
        AlphaRey &middot; {{ $employee->company?->name }} &middot; #{{ $consent->id }} &middot;
        RGPD art. 7 &amp; 13 &middot; LOPDGDD 3/2018 &middot; RD-ley 8/2019
    </div>

    <table>
        <tr><td class="k">Trabajador / Worker</td><td>{{ $employee->full_name }} ({{ $employee->employee_code }})</td></tr>
        <tr><td class="k">Empresa / Company</td><td>{{ $employee->company?->name }}</td></tr>
        <tr><td class="k">Versión / Version</td><td>{{ $consent->consent_version }}</td></tr>
        <tr><td class="k">Idioma mostrado / Language</td><td>{{ strtoupper($consent->language) }}</td></tr>
        <tr><td class="k">Fecha y hora / Timestamp</td><td>{{ $consent->consented_at->toDateTimeString() }} ({{ $consent->timezone }})</td></tr>
        <tr><td class="k">Dirección IP / IP address</td><td>{{ $consent->ip_address ?? '—' }}</td></tr>
        <tr><td class="k">Dispositivo / Device (user-agent)</td><td>{{ $consent->user_agent ?? '—' }}</td></tr>
    </table>

    <h2>Consentimientos / Consents</h2>
    <table>
        <tr>
            <td class="k">Registro horario / Attendance (obligatorio)</td>
            <td><span class="badge {{ $consent->consent_attendance ? 'yes' : 'no' }}">{{ $consent->consent_attendance ? 'ACEPTADO / ACCEPTED' : 'NO' }}</span></td>
        </tr>
        <tr>
            <td class="k">Geolocalización / GPS (opcional)</td>
            <td><span class="badge {{ $consent->consent_gps ? 'yes' : 'no' }}">{{ $consent->consent_gps ? 'SÍ / YES' : 'NO' }}</span></td>
        </tr>
        <tr>
            <td class="k">Selfie / Photo (opcional)</td>
            <td><span class="badge {{ $consent->consent_photo ? 'yes' : 'no' }}">{{ $consent->consent_photo ? 'SÍ / YES' : 'NO' }}</span></td>
        </tr>
        @if ($consent->revoked_at)
            <tr>
                <td class="k">Revocado / Revoked</td>
                <td><span class="badge rev">{{ $consent->revoked_at->toDateTimeString() }}</span> — {{ $consent->revoked_reason }}</td>
            </tr>
        @endif
    </table>

    <h2>Texto exacto mostrado / Exact text shown</h2>
    <div class="notice">{{ $consent->consent_text_shown }}</div>

    <p class="foot">
        Documento generado automáticamente como evidencia legal del consentimiento informado.
        Automatically generated as legal evidence of informed consent.
    </p>
</body>
</html>
