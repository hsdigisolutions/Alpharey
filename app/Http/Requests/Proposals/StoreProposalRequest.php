<?php

namespace App\Http\Requests\Proposals;

use App\Enums\ProposalStatus;
use App\Enums\VatRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('proposals.create');
    }

    /**
     * VAT is the optional VatRate dropdown (DECISIONS.md) — blank/null is
     * valid and means "No aplica".
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'proposal_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:proposal_date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'line_items' => ['nullable', 'array', 'max:100'],
            'line_items.*.description' => ['required_with:line_items', 'string', 'max:255'],
            'line_items.*.qty' => ['required_with:line_items', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['required_with:line_items', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', Rule::enum(VatRate::class)],
            'estimated_quantity' => ['nullable', 'numeric', 'min:0'],
            'estimated_total' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::enum(ProposalStatus::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
