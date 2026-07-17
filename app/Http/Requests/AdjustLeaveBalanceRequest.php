<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AdjustLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('leave_management.approve');
    }

    /**
     * `used` and `pending` are absent on purpose: they are derived from the
     * requests themselves, and letting a form set them would let the balance
     * disagree with the leave that produced it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'allocated' => ['required', 'numeric', 'min:0', 'max:999'],
            'carried_over' => ['required', 'numeric', 'min:0', 'max:999'],
        ];
    }
}
