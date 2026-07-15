<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Support\Facades\Gate;

class UpdateAttendanceRequest extends StoreAttendanceRequest
{
    public function authorize(): bool
    {
        return Gate::allows('attendance.edit');
    }
}
