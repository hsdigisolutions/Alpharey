<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Rules\OwnCompanyEmployee;
use App\Rules\OwnCompanyProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Bulk attendance creation: one set of details applied to N employees.
 *
 * Each employee_id is validated with OwnCompanyEmployee (same rule as the
 * single-entry request) so a cross-company id cannot sneak into any payroll.
 * Duplicate detection (same employee × date) is handled in the controller,
 * not here — the response carries both a created count and a skipped count.
 */
class StoreBulkAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('attendance.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', new OwnCompanyEmployee(allowDeployed: true)],
            'project_id' => ['nullable', 'integer', new OwnCompanyProject],
            'date' => ['required', 'date'],
            'mode' => ['required', Rule::enum(AttendanceMode::class)],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i', 'after:check_in'],
            'break_hours' => ['nullable', 'numeric', 'min:0', 'max:12'],
            'deduct_break' => ['boolean'],
            'hours_worked' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
