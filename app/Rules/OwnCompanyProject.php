<?php

namespace App\Rules;

use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The project named in the request must belong to the ACTING company —
 * the project-side twin of OwnCompanyEmployee. An invoice, expense or
 * measurement filed against another company's project pollutes that
 * company's project costing.
 */
class OwnCompanyProject implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $companyId = app(CurrentCompany::class)->id();

        if ($companyId === null) {
            $fail(__('ui.validation.no_company_context'));

            return;
        }

        $ok = Project::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('id', $value)
            ->where('company_id', $companyId)
            ->exists();

        if (! $ok) {
            $fail(__('ui.validation.project_not_of_company'));
        }
    }
}
