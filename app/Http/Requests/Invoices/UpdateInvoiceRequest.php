<?php

namespace App\Http\Requests\Invoices;

use Illuminate\Support\Facades\Gate;

/**
 * Same shape as store — the number stays fixed, totals stay derived.
 */
class UpdateInvoiceRequest extends StoreInvoiceRequest
{
    public function authorize(): bool
    {
        return Gate::allows('invoices.edit');
    }
}
