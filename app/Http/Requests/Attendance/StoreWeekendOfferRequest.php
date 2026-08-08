<?php

namespace App\Http\Requests\Attendance;

use App\Enums\WeekendRateType;
use App\Rules\OwnCompanyEmployee;
use App\Rules\OwnCompanyProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWeekendOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('attendance.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offer_date' => ['required', 'date'],
            'project_id' => ['nullable', 'integer', new OwnCompanyProject],
            'weekend_rate_type' => ['required', Rule::enum(WeekendRateType::class)],
            'weekend_rate_amount' => ['nullable', 'required_if:weekend_rate_type,custom', 'numeric', 'min:0'],
            'invited_employee_ids' => ['required', 'array', 'min:1'],
            'invited_employee_ids.*' => ['integer', new OwnCompanyEmployee],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $date = $this->input('offer_date');

            if (is_string($date) && $date !== '' && ! Carbon::parse($date)->isWeekend()) {
                // An "offer" only makes sense on a day that is off by default.
                $v->errors()->add('offer_date', __('ui.attendance.offer_not_weekend'));
            }
        });
    }
}
