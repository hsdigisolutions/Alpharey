<?php

namespace App\Http\Requests\Projects;

use Illuminate\Support\Facades\Gate;

class UpdateProjectRequest extends StoreProjectRequest
{
    public function authorize(): bool
    {
        return Gate::allows('projects.edit');
    }
}
