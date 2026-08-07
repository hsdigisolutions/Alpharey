<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Enums\DayType;
use App\Rules\OwnCompanyEmployee;
use App\Rules\OwnCompanyProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('attendance.create');
    }

    /**
     * company_id is never accepted; wage snapshots are frozen server-side.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Own employees plus workers deployed INTO this company (Phase 5) —
            // any other id would write pay data into a foreign payroll.
            'employee_id' => ['required', 'integer', new OwnCompanyEmployee(allowDeployed: true)],
            'project_id' => ['nullable', 'integer', new OwnCompanyProject],
            'date' => ['required', 'date'],
            'mode' => ['required', Rule::enum(AttendanceMode::class)],
            'day_type' => ['nullable', Rule::enum(DayType::class)],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i', 'after:check_in'],
            'break_hours' => ['nullable', 'numeric', 'min:0', 'max:12'],
            'deduct_break' => ['boolean'],
            'hours_worked' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:100000', 'required_if:day_type,per_meter'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'manual_wage_override' => ['boolean'],
            'is_paid' => ['boolean'],
            'is_exception' => ['boolean'],
            'exception_reason' => ['nullable', 'string', 'max:255', 'required_if:is_exception,true'],
            'work_mode' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
