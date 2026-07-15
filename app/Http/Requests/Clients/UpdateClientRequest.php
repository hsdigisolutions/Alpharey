<?php

namespace App\Http\Requests\Clients;

use Illuminate\Support\Facades\Gate;

class UpdateClientRequest extends StoreClientRequest
{
    public function authorize(): bool
    {
        return Gate::allows('clients.edit');
    }
}
