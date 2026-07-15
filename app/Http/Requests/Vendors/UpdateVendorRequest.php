<?php

namespace App\Http\Requests\Vendors;

use Illuminate\Support\Facades\Gate;

class UpdateVendorRequest extends StoreVendorRequest
{
    public function authorize(): bool
    {
        return Gate::allows('vendors.edit');
    }
}
