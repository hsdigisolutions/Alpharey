<?php

namespace App\Services\Workers;

use App\Models\Employee;
use App\Models\WorkerConsent;
use App\Support\WorkerPrivacyNotice;
use Illuminate\Http\Request;

/**
 * The single writer for worker privacy consent (legal evidence). The table is
 * append-only: a change (re-accept, preference toggle, revocation, admin reset)
 * REVOKES the current row and, where a new state exists, inserts a fresh row —
 * the full history is preserved for evidence.
 */
class WorkerConsentService
{
    /**
     * The consent currently in force for a worker: the latest, non-revoked row
     * matching the CURRENT notice version. Null when they must (re-)accept.
     */
    public function activeConsent(Employee $employee): ?WorkerConsent
    {
        return WorkerConsent::query()
            ->where('employee_id', $employee->id)
            ->whereNull('revoked_at')
            ->where('consent_version', WorkerPrivacyNotice::currentVersion())
            ->latest('consented_at')
            ->first();
    }

    /** Has the worker acknowledged the current notice (mandatory attendance)? */
    public function hasConsented(Employee $employee): bool
    {
        return $this->activeConsent($employee)?->consent_attendance === true;
    }

    /**
     * Record a new acceptance. Attendance is always true (the mandatory
     * information-duty acknowledgement — validated at the controller); GPS and
     * selfie are the worker's optional choices. Supersedes any active row.
     */
    public function record(Employee $employee, Request $request, bool $gps, bool $photo, string $language): WorkerConsent
    {
        $this->supersedeActive($employee, 'Superseded by a new acceptance');

        return $this->store($employee, $request, true, $gps, $photo, $language);
    }

    /**
     * Change the optional GPS/selfie preferences (e.g. the worker revokes GPS
     * from their profile). Supersedes the active row and writes a new one that
     * keeps the attendance acknowledgement but reflects the new choices.
     */
    public function updatePreferences(Employee $employee, Request $request, bool $gps, bool $photo, string $reason): WorkerConsent
    {
        $current = $this->activeConsent($employee);
        $user = $request->user();
        $language = $current !== null
            ? $current->language
            : ($user !== null && $user->locale === 'en' ? 'en' : 'es');

        $this->supersedeActive($employee, $reason);

        return $this->store($employee, $request, true, $gps, $photo, $language);
    }

    /**
     * Fully withdraw / admin-reset: revoke the active row and write NO new one,
     * so the worker has no active consent and must accept again before punching.
     */
    public function revoke(Employee $employee, string $reason): void
    {
        $this->supersedeActive($employee, $reason);
    }

    private function supersedeActive(Employee $employee, string $reason): void
    {
        $active = $this->activeConsent($employee);

        if ($active !== null) {
            $active->revoked_at = now();
            $active->revoked_reason = $reason;
            $active->save();
        }
    }

    private function store(Employee $employee, Request $request, bool $attendance, bool $gps, bool $photo, string $language): WorkerConsent
    {
        $language = $language === 'en' ? 'en' : 'es';

        $consent = new WorkerConsent([
            'employee_id' => $employee->id,
            'user_id' => $employee->user_id,
            'company_id' => $employee->company_id,
            'consent_version' => WorkerPrivacyNotice::currentVersion(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'consented_at' => now(),
            'timezone' => (string) (config('app.timezone') ?: 'Europe/Madrid'),
            'consent_attendance' => $attendance,
            'consent_gps' => $gps,
            'consent_photo' => $photo,
            'consent_text_shown' => WorkerPrivacyNotice::canonicalText($language),
            'language' => $language,
        ]);
        $consent->save();

        return $consent;
    }
}
