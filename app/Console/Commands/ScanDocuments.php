<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\DocumentAlertNotification;
use App\Services\Settings\SettingsService;
use App\Services\Vehicles\VehicleCompliance;
use App\Support\DocumentTypes;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Daily document scan implementing the CONFIRMED alert schedules
 * (DECISIONS.md):
 *
 *  Annual/event documents:  warnings at exactly 90/60/30 days before
 *  expiry + a critical alert on the expiry day itself.
 *  Monthly documents (4):   first reminder 5 days before month end,
 *  urgent reminder 2 days before, overdue alert on the 1st if the
 *  previous month was never uploaded — to the company's Company Admins,
 *  with a cross-company overdue summary to Super Admins on the 1st.
 *
 * System context: queries opt out of the tenancy scope explicitly.
 */
class ScanDocuments extends Command
{
    protected $signature = 'verto:scan-documents';

    protected $description = 'Send document expiry and monthly-upload alerts (DECISIONS.md schedules)';

    public function handle(SettingsService $settings): int
    {
        $warnDays = $settings->get('documents.warn_days', [90, 60, 30]);
        /** @var list<int> $warnDays */
        $warnDays = is_array($warnDays) ? array_values(array_map(intval(...), $warnDays)) : [90, 60, 30];

        $sent = 0;
        $sent += $this->scanExpiries($warnDays);
        $sent += $this->scanMonthlies();
        $sent += $this->scanVehicleExpiries($warnDays);

        $this->components->info("Document scan complete — {$sent} notification group(s) sent.");

        return self::SUCCESS;
    }

    /**
     * Phase 7: a vehicle's insurance and ITV are compliance dates like any
     * other, so they ride the SAME 90/60/30 + expiry-day schedule and reach
     * the same Company Admins. An uninsured van on the road is exactly the
     * kind of thing this system exists to catch.
     *
     * @param  list<int>  $warnDays
     */
    private function scanVehicleExpiries(array $warnDays): int
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(max($warnDays))->toDateString();
        $sent = 0;

        // System context: sweep every company's fleet (Rule 1 opt-out).
        $vehicles = Vehicle::query()
            ->withoutGlobalScopes()
            ->where('active', true)
            ->where(function ($q) use ($today, $horizon): void {
                foreach (VehicleCompliance::EXPIRY_FIELDS as $column) {
                    // Date-string bounds so a same-day row matches under both
                    // MySQL and SQLite (the Phase 2 lesson).
                    $q->orWhereBetween($column, [$today->toDateString(), $horizon]);
                }
            })
            ->with('company:id,name')
            ->get();

        foreach ($vehicles as $vehicle) {
            foreach (VehicleCompliance::EXPIRY_FIELDS as $field => $column) {
                $date = $vehicle->getAttribute($column);

                if ($date === null) {
                    continue;
                }

                $daysLeft = (int) $today->diffInDays($date->startOfDay(), false);

                if (! in_array($daysLeft, $warnDays, true) && $daysLeft !== 0) {
                    continue;
                }

                $label = __('ui.vehicles.'.$field).' — '.$vehicle->plate_number;

                $this->notifyCompanyAdmins($vehicle->company_id, [
                    'kind' => $daysLeft === 0 ? 'expired' : 'expiring',
                    'type' => 'vehicle_expiry',
                    'url' => '/vehicles',
                    'title_es' => $daysLeft === 0
                        ? "Vehículo — vence hoy: {$label}"
                        : "Vehículo — vence en {$daysLeft} días: {$label}",
                    'title_en' => $daysLeft === 0
                        ? "Vehicle — expires today: {$label}"
                        : "Vehicle — expires in {$daysLeft} days: {$label}",
                    'entity' => $label,
                    'company' => $vehicle->company?->name,
                    'days' => $daysLeft,
                    'document_id' => null,
                ]);

                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  list<int>  $warnDays
     */
    private function scanExpiries(array $warnDays): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        // today..today+maxWarn already includes the expiry day itself
        $documents = Document::query()
            ->withoutGlobalScopes()
            ->where('is_current', true)
            ->where('is_exempt', false)
            ->whereNotNull('expiry_date')
            // Date-string bounds so a same-day (expiry today) row is included
            // under both MySQL and SQLite comparison semantics
            ->whereBetween('expiry_date', [
                $today->toDateString(),
                $today->copy()->addDays(max($warnDays))->toDateString(),
            ])
            ->get();

        foreach ($documents as $document) {
            $daysLeft = (int) $today->diffInDays($document->expiry_date?->startOfDay(), false);

            $isMilestone = in_array($daysLeft, $warnDays, true) || $daysLeft === 0;

            if (! $isMilestone) {
                continue;
            }

            $label = $document->name ?? $document->type_key;

            $this->notifyCompanyAdmins($document->company_id, [
                'kind' => $daysLeft === 0 ? 'expired' : 'expiring',
                'type' => $daysLeft === 0 ? 'document_expired' : 'document_expiry',
                'url' => '/documents',
                'title_es' => $daysLeft === 0
                    ? "Documento vencido hoy: {$label}"
                    : "Documento vence en {$daysLeft} días: {$label}",
                'title_en' => $daysLeft === 0
                    ? "Document expires today: {$label}"
                    : "Document expires in {$daysLeft} days: {$label}",
                'entity' => $label,
                'company' => $document->company?->name,
                'days' => $daysLeft,
                'document_id' => $document->id,
            ]);

            $sent++;
        }

        return $sent;
    }

    private function scanMonthlies(): int
    {
        $today = now()->startOfDay();
        $daysToMonthEnd = (int) $today->diffInDays($today->copy()->endOfMonth()->startOfDay(), false);
        $isFirstOfMonth = $today->day === 1;

        if ($daysToMonthEnd !== 5 && $daysToMonthEnd !== 2 && ! $isFirstOfMonth) {
            return 0;
        }

        $sent = 0;
        $overdueSummary = [];

        $companies = Company::query()->where('status', 'active')->get();

        foreach ($companies as $company) {
            foreach (DocumentTypes::monthlyCompanyKeys() as $typeKey) {
                $period = $isFirstOfMonth ? $today->copy()->subMonth() : $today;

                $uploaded = Document::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('type_key', $typeKey)
                    ->whereNotNull('file_path')
                    ->whereYear('created_at', $period->year)
                    ->whereMonth('created_at', $period->month)
                    ->exists();

                if ($uploaded) {
                    continue;
                }

                if ($isFirstOfMonth) {
                    $overdueSummary[] = "{$company->name}: {$typeKey}";
                }

                $kind = $isFirstOfMonth ? 'monthly_overdue' : ($daysToMonthEnd === 2 ? 'monthly_urgent' : 'monthly_due');

                $this->notifyCompanyAdmins($company->id, [
                    'kind' => $kind,
                    'type' => $kind === 'monthly_overdue' ? 'document_expired' : 'document_expiry',
                    'url' => '/documents',
                    'title_es' => match ($kind) {
                        'monthly_overdue' => "Documento mensual NO subido el mes pasado: {$typeKey}",
                        'monthly_urgent' => "Urgente: documento mensual pendiente (quedan 2 días): {$typeKey}",
                        default => "Recordatorio: documento mensual pendiente este mes: {$typeKey}",
                    },
                    'title_en' => match ($kind) {
                        'monthly_overdue' => "Monthly document NOT uploaded last month: {$typeKey}",
                        'monthly_urgent' => "Urgent: monthly document pending (2 days left): {$typeKey}",
                        default => "Reminder: monthly document pending this month: {$typeKey}",
                    },
                    'entity' => $typeKey,
                    'company' => $company->name,
                    'days' => $isFirstOfMonth ? null : $daysToMonthEnd,
                    'document_id' => null,
                ]);

                $sent++;
            }
        }

        // Cross-company overdue summary to Super Admins on the 1st
        if ($isFirstOfMonth && $overdueSummary !== []) {
            $summary = implode(' · ', $overdueSummary);

            User::query()->where('role', UserRole::SuperAdmin->value)->where('active', true)->get()
                ->each(fn (User $admin) => $admin->notify(new DocumentAlertNotification([
                    'kind' => 'overdue_summary',
                    'type' => 'document_expired',
                    'url' => '/documents',
                    'title_es' => 'Resumen de documentos mensuales vencidos: '.$summary,
                    'title_en' => 'Overdue monthly documents summary: '.$summary,
                    'entity' => null,
                    'company' => null,
                    'days' => null,
                    'document_id' => null,
                ])));

            $sent++;
        }

        return $sent;
    }

    /**
     * @param  array{kind: string, title_es: string, title_en: string, entity: string|null, company: string|null, days: int|null, document_id: string|null, type?: string, url?: string}  $payload
     */
    private function notifyCompanyAdmins(?int $companyId, array $payload): void
    {
        $this->recipientsFor($companyId)->each(
            fn (User $user) => $user->notify(new DocumentAlertNotification($payload)),
        );
    }

    /**
     * Company Admins of the owning company; Super Admins are included for
     * expiry alerts on documents without a company (none currently).
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(?int $companyId): Collection
    {
        $query = User::query()->where('active', true);

        if ($companyId !== null) {
            $query->where('role', UserRole::Admin->value)->where('company_id', $companyId);
        } else {
            $query->where('role', UserRole::SuperAdmin->value);
        }

        return $query->get();
    }
}
