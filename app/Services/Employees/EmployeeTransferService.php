<?php

namespace App\Services\Employees;

use App\Enums\EquipmentIssueStatus;
use App\Enums\PayrollStatus;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeEquipmentIssue;
use App\Models\Payroll;
use App\Models\Scopes\CompanyScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transfer an employee from one company to another, KEEPING history (Change 2).
 *
 * Instead of moving the single record's company_id (which stranded the old
 * company's view), a transfer now:
 *   - keeps the OLD record as history, marked Transferred (transferred_out_at),
 *     deactivated and with its login released — its attendance, payroll,
 *     documents and wage history stay with the old company;
 *   - creates a NEW record in the target company sharing the same person_uuid
 *     (the link the Employment History tab follows), with a fresh employee_code,
 *     joining_date = the transfer date (so absence-counting starts then), the
 *     login moved onto it, fresh wage rates seeded from the carried wage fields,
 *     and its personal/qualification documents carried over.
 *
 * Blocked when: outstanding equipment issues exist, or the current month's
 * payroll for this employee is already PAID (kept as an extra safety guard even
 * though old and new records now have distinct employee_ids).
 */
class EmployeeTransferService
{
    /**
     * Personal identity + portable qualification documents that follow the
     * PERSON across companies. Employment docs (contrato, alta/baja SS, IDC,
     * art.18, EPIs, machinery authorisation) are company-specific and are
     * re-created fresh by the new company — deliberately NOT copied.
     */
    private const CARRY_OVER_DOC_TYPES = [
        'dni', 'nie_fotocopia', 'foto',
        'aptitud_medica', 'formacion_art19', 'formacion_prl_20h',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function transfer(Employee $employee, int $toCompanyId, string $date): Employee
    {
        $fromCompanyId = $employee->company_id;

        if ($toCompanyId === $fromCompanyId) {
            throw ValidationException::withMessages(['to_company_id' => __('ui.employees.transfer_same_company')]);
        }

        // Guard 1 — outstanding equipment (anything not fully returned).
        $hasOutstanding = EmployeeEquipmentIssue::query()->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->where('status', '!=', EquipmentIssueStatus::Returned->value)
            ->exists();
        if ($hasOutstanding) {
            throw ValidationException::withMessages(['transfer' => __('ui.employees.transfer_equipment_blocked')]);
        }

        // Guard 2 — a paid payroll in the CURRENT month locks the transfer.
        $paidThisMonth = Payroll::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('month', Carbon::parse($date)->format('Y-m'))
            ->where('status', PayrollStatus::Paid->value)
            ->exists();
        if ($paidThisMonth) {
            throw ValidationException::withMessages(['transfer' => __('ui.employees.transfer_paid_blocked')]);
        }

        return DB::transaction(function () use ($employee, $fromCompanyId, $toCompanyId, $date): Employee {
            // One shared person link across the whole transfer chain.
            if ($employee->person_uuid === null) {
                $employee->person_uuid = (string) Str::uuid();
            }
            $transferDate = Carbon::parse($date);

            // The NEW record — cloned from the current profile + wage fields
            // (replicate BEFORE the old record is mutated). A fresh stint.
            $new = $employee->replicate();
            $new->company_id = $toCompanyId; // creating hook only fills when null
            $new->employee_code = Employee::nextCode();
            $new->previous_company_id = $fromCompanyId;
            $new->transferred_at = now();
            $new->transferred_out_at = null;
            $new->active = true;
            $new->active_since = $transferDate;
            $new->joining_date = $transferDate;
            $new->leaving_date = null;
            $new->documents_pending_reupload = true;
            // person_uuid + user_id are carried by replicate().

            // The OLD record: kept as history, marked Transferred, deactivated,
            // and its login released FIRST so the unique user_id is free for the
            // new record.
            $employee->transferred_out_at = now();
            $employee->active = false;
            $employee->user_id = null;
            $employee->save();

            $new->save();

            // Fresh wage-rate history for the new stint (the old record keeps its
            // own rows for its historical payroll).
            app(WageRateService::class)->seedFromEmployee($new);

            // Carry over the personal + qualification documents to the new record.
            $this->carryOverDocuments($employee, $new);

            $this->audit->log(
                'transferred',
                $employee,
                ['status' => 'active'],
                ['status' => 'transferred'],
                "Transferred out to company {$toCompanyId} (new record #{$new->id})",
                'employees',
            );
            $this->audit->log(
                'created',
                $new,
                null,
                ['company_id' => $toCompanyId],
                "Transferred in from company {$fromCompanyId} (from record #{$employee->id})",
                'employees',
            );

            return $new;
        });
    }

    /**
     * Copy the person's carry-over documents (identity + portable
     * qualifications) from the old record to the new one, duplicating the
     * physical files so the new company owns its own copy.
     */
    private function carryOverDocuments(Employee $old, Employee $new): void
    {
        $docs = Document::query()->withoutGlobalScope(CompanyScope::class)
            ->where('documentable_type', $old->getMorphClass())
            ->where('documentable_id', $old->id)
            ->where('is_current', true)
            ->whereIn('type_key', self::CARRY_OVER_DOC_TYPES)
            ->get();

        foreach ($docs as $doc) {
            $copy = $doc->replicate(['file_path']);
            $copy->documentable()->associate($new);
            $copy->company_id = $new->company_id;
            $copy->version = 1;
            $copy->setAttribute('is_current', true);

            $oldPath = $doc->getAttribute('file_path');
            if (is_string($oldPath) && $oldPath !== '' && Storage::disk('local')->exists($oldPath)) {
                $ext = pathinfo($oldPath, PATHINFO_EXTENSION);
                $newPath = "employees/{$new->id}/documents/".Str::random(40).($ext !== '' ? '.'.$ext : '');
                Storage::disk('local')->copy($oldPath, $newPath);
                $copy->setAttribute('file_path', $newPath);
            }

            $copy->save();
        }
    }
}
