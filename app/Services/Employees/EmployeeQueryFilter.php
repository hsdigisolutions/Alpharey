<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The Screen 05 filter set, shared by the list page and the Excel/PDF
 * exports so "export the current filtered view" (§10) is literal.
 *
 * The 'transferred' status is special: under the single-record transfer model a
 * person's record has its company_id FLIPPED to the new company, so the old
 * company can no longer find them by the tenant scope. That status therefore
 * queries employee_company_history for a CLOSED stint at the acting company
 * (they worked here and left) — the only way the old company sees "previously
 * here" workers. Every other status is the normal tenant-scoped current list.
 */
class EmployeeQueryFilter
{
    /**
     * @return Builder<Employee>
     */
    public function apply(Request $request): Builder
    {
        $status = $request->string('status')->value();
        $companyId = app(CurrentCompany::class)->id();

        $query = $status === 'transferred'
            ? $this->transferredAwayQuery($companyId)
            : Employee::query()
                ->when($status === 'active', fn (Builder $q) => $q->where('active', true))
                ->when($status === 'inactive', fn (Builder $q) => $q->where('active', false));

        return $query
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->value();
                $q->where(function (Builder $q) use ($term): void {
                    $q->where('full_name', 'like', "%{$term}%")
                        ->orWhere('employee_code', 'like', "%{$term}%")
                        // Encrypted NIF is searched via its blind index
                        ->orWhere('nif_hash', Employee::hashNif($term));
                });
            })
            ->when($request->filled('department'), fn (Builder $q) => $q->where('department_id', $request->integer('department')))
            ->when($request->filled('designation'), fn (Builder $q) => $q->where('designation', $request->string('designation')))
            ->when($request->filled('wage_type'), fn (Builder $q) => $q->where('wage_type', $request->string('wage_type')));
    }

    /**
     * Employees who worked at the acting company and were TRANSFERRED AWAY: a
     * closed stint here in employee_company_history, whose single record now
     * lives at another company. The tenant scope is dropped because the record's
     * company_id is the NEW company now; SoftDeletes still excludes deleted rows
     * (so the pre-redesign orphan records never surface here).
     *
     * @return Builder<Employee>
     */
    private function transferredAwayQuery(?int $companyId): Builder
    {
        $query = Employee::query()->withoutGlobalScope(CompanyScope::class)
            ->whereHas('companyHistory', function (Builder $h) use ($companyId): void {
                $h->whereNotNull('ended_at');
                if ($companyId !== null) {
                    $h->where('company_id', $companyId);
                }
            });

        // Only those who are NOT currently here (they left) — a round-trip
        // worker back at this company shows in the normal active list instead.
        if ($companyId !== null) {
            $query->where('company_id', '!=', $companyId);
        }

        return $query;
    }
}
