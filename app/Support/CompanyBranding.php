<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * Company branding helpers for PDFs. DomPDF cannot fetch remote assets and the
 * CSP forbids external images, so a logo must be embedded as a base64 data URI.
 */
class CompanyBranding
{
    /**
     * The company logo as a base64 data URI, or null when no logo is uploaded
     * or the file is missing (the PDF then simply shows the company name).
     */
    public static function logoDataUri(?Company $company): ?string
    {
        $path = $company?->logo_path;
        if ($path === null || $path === '') {
            return null;
        }

        foreach (['public', 'local'] as $disk) {
            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }

            $bytes = Storage::disk($disk)->get($path);
            if ($bytes === null) {
                continue;
            }

            $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        }

        return null;
    }

    /**
     * The ACTING company's logo as a data URI — the one-call helper every
     * company-scoped PDF export uses (Change 3). Null when a Super Admin is
     * browsing all companies or the company has no logo.
     */
    public static function currentLogo(): ?string
    {
        return self::logoDataUri(app(CurrentCompany::class)->get());
    }
}
