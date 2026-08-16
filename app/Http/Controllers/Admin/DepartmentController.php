<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company departments (Settings → Departamentos). Simple inline CRUD, scoped to
 * the acting company by the Department tenancy trait (a cross-company id 404s on
 * route binding). Admin-only, mirroring the other Settings writes.
 *
 * A department that any employee is assigned to cannot be deleted — deactivate
 * it instead (it stays out of the form dropdown while existing employees keep
 * their label).
 */
class DepartmentController extends Controller
{
    private function authorizeAdmin(Request $request): int
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->isSuperAdmin() || $user->isCompanyAdmin()), 403);

        $companyId = app(CurrentCompany::class)->id();
        abort_if($companyId === null, 403);

        return $companyId;
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('departments', 'name')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
        ]);

        // company_id is set by the tenancy trait from the active company.
        Department::create(['name' => $data['name'], 'active' => true]);

        return back()->with('success', __('ui.settings.department_saved'));
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('departments', 'name')
                    ->where(fn ($q) => $q->where('company_id', $department->company_id))
                    ->ignore($department->id),
            ],
            'active' => ['required', 'boolean'],
        ]);

        // Keep assigned employees' display string in sync on rename (they link
        // by department_id; the string is only the cached display value).
        if ($department->name !== $data['name']) {
            Employee::query()
                ->where('department_id', $department->id)
                ->update(['department' => $data['name']]);
        }

        $department->update($data);

        return back()->with('success', __('ui.settings.department_saved'));
    }

    public function destroy(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeAdmin($request);

        // Block the delete when employees are assigned — deactivate instead.
        if ($department->employeeCount() > 0) {
            throw ValidationException::withMessages([
                'department' => __('ui.settings.department_in_use'),
            ]);
        }

        $department->delete();

        return back()->with('success', __('ui.settings.department_deleted'));
    }
}
