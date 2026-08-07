<?php

namespace App\Http\Controllers;

use App\Enums\WageType;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\EmployeeWageRate;
use App\Models\Payroll;
use App\Models\UserColumnSetting;
use App\Services\Documents\DocumentStatus;
use App\Services\Employees\EmployeeQueryFilter;
use App\Services\Employees\EmployeeService;
use App\Services\Employees\WageRateService;
use App\Support\DocumentTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screens 05 (list) + 06 (detail). The list implements the Data
 * Management Standards (§10): sort whitelist, multi-filter, live search
 * (code/name + NIF via blind index), column visibility per user,
 * pagination 25/50/100. Every payload is permission-filtered server-side.
 */
class EmployeeController extends Controller
{
    private const SORTABLE = ['employee_code', 'full_name', 'department', 'designation', 'joining_date', 'created_at'];

    public function index(Request $request, DocumentStatus $status, EmployeeQueryFilter $filter): Response
    {
        Gate::authorize('employees.view');

        // Eager-load both relations the row shape reads (documents for the
        // compliance dot, company for its name) — without `company` the list
        // fires one query per employee (found by the Phase 9 N+1 guard).
        $query = $filter->apply($request)->with(['documents', 'company:id,name']);

        $sort = in_array($request->string('sort')->value(), self::SORTABLE, true)
            ? $request->string('sort')->value()
            : 'full_name';
        $dir = $request->string('dir')->value() === 'desc' ? 'desc' : 'asc';

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true)
            ? $request->integer('per_page')
            : 25;

        $canSeeWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');

        $employees = $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'company' => $employee->company?->name,
                'department' => $employee->department,
                'designation' => $employee->designation,
                'city' => $employee->city,
                'mobile' => $employee->mobile,
                'wage_type' => $employee->wage_type?->value,
                // Salary figures only for roles that manage wages (server-side filter)
                'wage_rate' => $canSeeWages ? $employee->getAttribute('wage_rate') : null,
                'base_salary' => $canSeeWages ? $employee->getAttribute('base_salary') : null,
                'commission_percent' => $canSeeWages ? $employee->commission_percent : null,
                'active' => $employee->active,
                'doc_status' => $status->worst($employee->documents),
            ]);

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'filters' => $request->only(['search', 'status', 'department', 'designation', 'wage_type', 'sort', 'dir', 'per_page']),
            'filterOptions' => [
                'departments' => Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
                'designations' => Employee::query()->whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation'),
                'wageTypes' => array_map(fn (WageType $type) => $type->value, WageType::cases()),
            ],
            'visibleColumns' => UserColumnSetting::for($request->user(), 'employees'),
            'canSeeWages' => $canSeeWages,
            'can' => [
                'create' => Gate::allows('employees.create'),
                'edit' => Gate::allows('employees.edit'),
                'delete' => Gate::allows('employees.delete'),
                'export' => Gate::allows('employees.export'),
            ],
        ]);
    }

    public function show(Request $request, Employee $employee, DocumentStatus $status, WageRateService $wageRates): Response
    {
        Gate::authorize('employees.view');

        $canSeeWages = Gate::allows('payroll.view') || Gate::allows('employees.edit');
        $canSeeBank = $canSeeWages;

        return Inertia::render('Employees/Detail', [
            'employee' => array_merge($employee->only([
                'id', 'employee_code', 'full_name', 'email', 'mobile', 'phone', 'city', 'address',
                'department', 'designation', 'team_leader_id', 'active', 'is_contracted',
                'default_check_in', 'default_check_out', 'commission_percent',
                'has_driving_license', 'has_company_vehicle', 'can_use_vehicles', 'notes',
            ]), [
                'joining_date' => $employee->joining_date?->toDateString(),
                'leaving_date' => $employee->leaving_date?->toDateString(),
                'company' => $employee->company?->name,
                'team_leader' => $employee->teamLeader?->full_name,
                'wage_type' => $employee->wage_type?->value,
                'payment_method' => $employee->payment_method?->value,
                // Sensitive fields: explicit, permission-gated exposure only
                'nif' => $employee->nif,
                'iban' => $canSeeBank ? $employee->iban : null,
                'bank_name' => $canSeeBank ? $employee->bank_name : null,
                'wage_rate' => $canSeeWages ? $employee->getAttribute('wage_rate') : null,
                'base_salary' => $canSeeWages ? $employee->getAttribute('base_salary') : null,
                'daily_wage' => $canSeeWages ? $employee->getAttribute('daily_wage') : null,
                'per_meter_rate' => $canSeeWages ? $employee->getAttribute('per_meter_rate') : null,
            ]),
            // Mobile PWA access (Worker PWA). Only whether a login exists and
            // which address it uses — never anything about the credential.
            'appAccess' => [
                'email' => $employee->user?->email,
                'active' => (bool) $employee->user?->active,
            ],
            'documents' => $this->documentsPayload($employee, $status),
            'documentSets' => DocumentTypes::employee(),
            'notes' => $employee->employeeNotes()->with('author:id,name')->orderByDesc('noted_at')->get()
                ->map(fn ($note) => [
                    'id' => $note->id,
                    'type' => $note->type,
                    'body' => $note->body,
                    'noted_at' => $note->noted_at->toDateTimeString(),
                    'author' => $note->author?->name,
                    'attachment_name' => $note->attachment_name,
                ]),
            'calls' => $employee->callLogs()->with('caller:id,name')->orderByDesc('called_at')->get()
                ->map(fn ($call) => [
                    'id' => $call->id,
                    'called_at' => $call->called_at->toDateTimeString(),
                    'called_by' => $call->caller?->name,
                    'remarks' => $call->remarks,
                    'follow_up_date' => $call->follow_up_date?->toDateString(),
                ]),
            // Nómina tab — pay data, so only for a wage viewer; empty otherwise.
            'payroll' => $canSeeWages ? $this->payrollRows($employee) : [],
            // Historial de Salario — the effective-dated wage timeline.
            'wageHistory' => $canSeeWages ? $this->wageHistoryRows($employee, $wageRates) : [],
            'canSeeWages' => $canSeeWages,
            'can' => [
                'edit' => Gate::allows('employees.edit'),
                'delete' => Gate::allows('employees.delete'),
                'manageWages' => Gate::allows('employees.edit'),
                'upload' => Gate::allows('documents.upload'),
                'download' => Gate::allows('documents.download'),
                'deleteDocs' => Gate::allows('documents.delete'),
            ],
        ]);
    }

    /**
     * The employee's payroll history for the Nómina tab. Figures are encrypted
     * at rest and decrypted here; the caller only reaches this behind the wage
     * gate. The employee belongs to the acting company, so the tenant scope on
     * Payroll resolves to the right rows.
     *
     * @return list<array<string, mixed>>
     */
    private function payrollRows(Employee $employee): array
    {
        return Payroll::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('month')
            ->get()
            ->map(fn (Payroll $p): array => [
                'id' => $p->id,
                'month' => $p->month,
                'gross_pay' => (float) $p->getAttribute('gross_pay'),
                'net_amount' => (float) $p->getAttribute('net_amount'),
                'status' => $p->status->value,
                'paid_at' => $p->paid_at?->toDateString(),
            ])
            ->all();
    }

    /**
     * The effective-dated wage timeline for the Historial de Salario section.
     * Rates are encrypted at rest and decrypted here, behind the wage gate; the
     * record covering today is flagged as the current (active) rate.
     *
     * @return list<array<string, mixed>>
     */
    private function wageHistoryRows(Employee $employee, WageRateService $wageRates): array
    {
        $currentId = $wageRates->rateForDate($employee->id, now()->toDateString())?->id;

        return $wageRates->history($employee)
            ->map(fn (EmployeeWageRate $r): array => [
                'id' => $r->id,
                'wage_type' => $r->wage_type?->value,
                'rate' => (float) $r->rate,
                'effective_from' => $r->effective_from->toDateString(),
                'effective_to' => $r->effective_to?->toDateString(),
                'reason' => $r->reason,
                'is_current' => $r->id === $currentId,
            ])
            ->all();
    }

    public function store(StoreEmployeeRequest $request, EmployeeService $service): RedirectResponse
    {
        $service->create($request->validated());

        return back()->with('success', __('ui.employees.saved'));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $service->update($employee, $request->validated());

        return back()->with('success', __('ui.employees.saved'));
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('employees.delete');

        $employee->delete(); // soft delete — employees are never hard-deleted

        return redirect()->route('employees.index')->with('success', __('ui.employees.deleted'));
    }

    /**
     * Bulk activate/deactivate — the Phase 2 bulk actions.
     */
    public function bulkActive(Request $request): RedirectResponse
    {
        Gate::authorize('employees.edit');

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'active' => ['required', 'boolean'],
        ]);

        // The company scope confines the update to the caller's company rows
        Employee::query()->whereIn('id', $validated['ids'])->get()
            ->each(fn (Employee $employee) => $employee->update(['active' => $validated['active']]));

        return back()->with('success', __('ui.employees.saved'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentsPayload(Employee $employee, DocumentStatus $status): array
    {
        return $employee->documents()->where('is_current', true)->get()
            ->map(function ($document) use ($status): array {
                [$state, $daysLeft] = $status->of($document);

                return [
                    'id' => $document->id,
                    'category' => $document->category,
                    'type_key' => $document->type_key,
                    'name' => $document->name,
                    'original_name' => $document->original_name,
                    'has_file' => $document->file_path !== null,
                    'has_flag' => $document->has_flag,
                    'issue_date' => $document->issue_date?->toDateString(),
                    'expiry_date' => $document->expiry_date?->toDateString(),
                    'version' => $document->version,
                    'status' => $state,
                    'days_left' => $daysLeft,
                    'notes' => $document->notes,
                ];
            })->values()->all();
    }
}
