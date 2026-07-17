<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Approve / reject. Cancelling is a separate, weaker permission (a request can
 * be withdrawn by whoever may edit it), so it does not use this class.
 */
class ReviewLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('leave_management.approve');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
