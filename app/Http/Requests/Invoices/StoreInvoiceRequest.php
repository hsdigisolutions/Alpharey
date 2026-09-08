<?php

namespace App\Http\Requests\Invoices;

use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceSubType;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\VatRate;
use App\Rules\OwnCompanyProject;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * `number`, every total, and payment_status are deliberately ABSENT: the
 * server generates the number and InvoiceTotals derives the money. Anything a
 * client sends for those is ignored.
 */
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('invoices.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(InvoiceType::class)],
            'sub_type' => ['required', Rule::enum(InvoiceSubType::class)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')],
            'project_id' => ['nullable', 'integer', new OwnCompanyProject],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'billing_type' => ['nullable', 'string', 'max:30'],
            'billing_period' => ['nullable', 'string', 'max:40'],

            // VAT: the VatRate dropdown only, and blank is valid (No aplica).
            'is_taxable' => ['nullable', 'boolean'],
            'vat_rate' => ['nullable', Rule::enum(VatRate::class)],
            // A custom rate needs its percentage; other rates ignore it.
            'vat_custom_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:vat_rate,custom'],
            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'retention_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'status' => ['required', Rule::enum(InvoiceStatus::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * A sale is to a client, an expense is from a vendor — enforce the pairing
     * the two tabs imply rather than letting a sale be filed against a vendor.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');

            if ($type === InvoiceType::Sale->value && $this->input('client_id') === null) {
                $validator->errors()->add('client_id', __('ui.invoices.client_required'));
            }

            if ($type === InvoiceType::Expense->value && $this->input('vendor_id') === null) {
                $validator->errors()->add('vendor_id', __('ui.invoices.vendor_required'));
            }
        });
    }
}
