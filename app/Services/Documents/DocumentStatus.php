<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Services\Settings\SettingsService;
use App\Support\DocumentTypes;
use Illuminate\Support\Carbon;

/**
 * Traffic-light logic for every document surface (REQUIREMENTS.md §7):
 * ok (valid) · warn (expiring) · danger (expired/overdue) · neutral
 * (missing / monthly pending) — plus 'exempt' which drops out of scoring.
 */
class DocumentStatus
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Warn threshold in days (the widest of the configured 90/60/30 set).
     */
    public function warnDays(): int
    {
        $days = $this->settings->get('documents.warn_days', [90, 60, 30]);

        return is_array($days) && $days !== [] ? max(array_map(intval(...), $days)) : 90;
    }

    /**
     * Danger threshold in days (the narrowest of the set — default 30).
     * Anything at or below this before expiry is graded 'danger', not 'warn',
     * so a document expiring in 2 days doesn't look merely amber.
     */
    public function dangerDays(): int
    {
        $days = $this->settings->get('documents.warn_days', [90, 60, 30]);

        return is_array($days) && $days !== [] ? min(array_map(intval(...), $days)) : 30;
    }

    /**
     * @return array{0: string, 1: int|null} status + days remaining
     */
    public function of(Document $document): array
    {
        if ($document->is_exempt) {
            return ['exempt', null];
        }

        // Monthly company documents: fresh upload expected every month
        if ($document->category === 'company'
            && in_array($document->type_key, DocumentTypes::monthlyCompanyKeys(), true)) {
            return $this->monthlyStatus($document);
        }

        if ($document->file_path === null && $document->has_flag !== true) {
            return ['neutral', null];
        }

        return $this->expiryGrade($document->expiry_date);
    }

    /**
     * Grade a single expiry date against the 90/60/30 + expiry-day windows.
     * No date is 'ok' (nothing to expire).
     *
     * @return array{0: string, 1: int|null}
     */
    private function expiryGrade(?Carbon $expiry): array
    {
        if ($expiry === null) {
            return ['ok', null];
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays($expiry->startOfDay(), false);

        if ($daysLeft < 0 || $daysLeft <= $this->dangerDays()) {
            return ['danger', $daysLeft];
        }

        if ($daysLeft <= $this->warnDays()) {
            return ['warn', $daysLeft];
        }

        return ['ok', $daysLeft];
    }

    /**
     * Worst status across a set of documents (employee row indicator,
     * company compliance dot).
     *
     * @param  iterable<Document>  $documents
     */
    public function worst(iterable $documents): string
    {
        $rank = ['danger' => 3, 'warn' => 2, 'neutral' => 1, 'ok' => 0, 'exempt' => 0];
        $worst = 'ok';

        foreach ($documents as $document) {
            [$status] = $this->of($document);

            if (($rank[$status] ?? 0) > $rank[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /**
     * Score contribution for compliance percentages: ok 1 · warn/pending
     * 0.5 · danger/missing 0 · exempt excluded (null).
     */
    public function score(Document $document): ?float
    {
        [$status] = $this->of($document);

        return match ($status) {
            'exempt' => null,
            'ok' => 1.0,
            'warn', 'neutral' => 0.5,
            default => 0.0,
        };
    }

    /**
     * Monthly company documents grade on the WORSE of two rules (client Q1):
     * the month-end refresh cadence AND the certificate's own valid_until.
     *
     * @return array{0: string, 1: int|null}
     */
    private function monthlyStatus(Document $document): array
    {
        $cadence = $this->monthlyCadence($document);

        if ($document->expiry_date === null) {
            return $cadence;
        }

        return $this->worse($cadence, $this->expiryGrade($document->expiry_date));
    }

    /**
     * Month-end refresh cadence. "Uploaded this month" is keyed on the current
     * version's created_at (its upload time) — NOT updated_at, so a later
     * metadata edit can't fabricate compliance.
     *
     * @return array{0: string, 1: int|null}
     */
    private function monthlyCadence(Document $document): array
    {
        $uploadedThisMonth = $document->file_path !== null
            && $document->created_at !== null
            && $document->created_at->isSameMonth(now());

        if ($uploadedThisMonth) {
            return ['ok', null];
        }

        $daysToMonthEnd = (int) now()->startOfDay()->diffInDays(now()->endOfMonth()->startOfDay(), false);

        // First reminder window opens 5 days before month end (DECISIONS.md)
        return $daysToMonthEnd <= 5 ? ['warn', $daysToMonthEnd] : ['neutral', null];
    }

    /**
     * The worse of two graded results (higher traffic-light rank wins).
     *
     * @param  array{0: string, 1: int|null}  $a
     * @param  array{0: string, 1: int|null}  $b
     * @return array{0: string, 1: int|null}
     */
    private function worse(array $a, array $b): array
    {
        $rank = ['danger' => 3, 'warn' => 2, 'neutral' => 1, 'ok' => 0, 'exempt' => 0];

        return ($rank[$a[0]] ?? 0) >= ($rank[$b[0]] ?? 0) ? $a : $b;
    }
}
