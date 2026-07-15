<?php

namespace App\Http\Requests\Proposals;

use Illuminate\Support\Facades\Gate;

class UpdateProposalRequest extends StoreProposalRequest
{
    public function authorize(): bool
    {
        return Gate::allows('proposals.edit');
    }
}
