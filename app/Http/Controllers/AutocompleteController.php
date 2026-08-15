<?php

namespace App\Http\Controllers;

use App\Services\Autocomplete\DescriptionAutocomplete;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lightweight JSON autocomplete for free-text fields (like the global-search
 * endpoint, this is a keystroke-driven fetch, not an Inertia page). Company
 * scoping lives in the service; this just hands over the acting company id.
 */
class AutocompleteController extends Controller
{
    public function __construct(private readonly DescriptionAutocomplete $descriptions) {}

    public function descriptions(Request $request, CurrentCompany $company): JsonResponse
    {
        // The pool is invoice + expense line items, so the caller must be able to
        // see one of those modules (Rule 2 — no data endpoint is ungated).
        abort_unless(Gate::allows('invoices.view') || Gate::allows('expenses.view'), 403);

        $companyId = $company->id();

        // A Super Admin browsing "all companies" has no single company to scope
        // to — return nothing rather than leak across the group.
        $suggestions = $companyId === null
            ? []
            : $this->descriptions->descriptions((string) $request->query('q', ''), $companyId);

        return response()->json(['suggestions' => $suggestions]);
    }
}
