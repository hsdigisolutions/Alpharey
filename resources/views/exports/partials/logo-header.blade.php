{{--
    Change 3 — per-company branding header for exported PDFs.
    Renders the ACTING company's own logo (base64 data URI via
    CompanyBranding::logoDataUri), never the generic AlphaRey mark. Silently
    absent when the company has no logo uploaded.
--}}
@if (! empty($logo ?? null))
    <div style="margin-bottom:10px;">
        <img src="{{ $logo }}" alt="" style="max-height:52px; max-width:180px;">
    </div>
@endif
