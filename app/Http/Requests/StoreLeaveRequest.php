<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\LeaveCategory;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('leave_management.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // exists() alone would let one company file leave against
            // another's worker; the scope check is in withValidator().
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_category_id' => ['required', 'integer', 'exists:leave_categories,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertEmployeeInCompany($validator);
            $this->assertCategoryAvailable($validator);
            $this->assertDaysFitTheSpan($validator);
        });
    }

    /**
     * The employee must belong to the acting company. Employee's global scope
     * does the work — a foreign id simply resolves to nothing.
     */
    private function assertEmployeeInCompany(Validator $validator): void
    {
        $exists = Employee::query()->whereKey($this->integer('employee_id'))->exists();

        if (! $exists) {
            $validator->errors()->add('employee_id', __('ui.leave.employee_not_found'));
        }
    }

    /**
     * Either a group-wide default or this company's own category — never
     * another company's.
     */
    private function assertCategoryAvailable(Validator $validator): void
    {
        $exists = LeaveCategory::query()
            ->forCompany(app(CurrentCompany::class)->id())
            ->where('active', true)
            ->whereKey($this->integer('leave_category_id'))
            ->exists();

        if (! $exists) {
            $validator->errors()->add('leave_category_id', __('ui.leave.category_not_found'));
        }
    }

    /**
     * total_days is entered by hand (half days are real), but it cannot exceed
     * the weekdays the request actually spans — otherwise a 2-day request
     * could quietly burn 20 days of somebody's balance.
     */
    private function assertDaysFitTheSpan(Validator $validator): void
    {
        if (! $this->filled(['start_date', 'end_date', 'total_days'])) {
            return;
        }

        $start = Carbon::parse($this->string('start_date')->value());
        $end = Carbon::parse($this->string('end_date')->value());

        if ($end->lt($start)) {
            return; // after_or_equal already reported this
        }

        $weekdays = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($cursor->isWeekday()) {
                $weekdays++;
            }

            $cursor->addDay();
        }

        if ((float) $this->input('total_days') > $weekdays) {
            $validator->errors()->add('total_days', __('ui.leave.days_exceed_span', ['days' => $weekdays]));
        }
    }
}
