<?php

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search endpoint (header bar, Phase 8). Returns grouped JSON for the
 * dropdown — the only non-Inertia GET in the app, because a keystroke-driven
 * dropdown wants a lightweight fetch, not a full page visit.
 *
 * All permission and tenancy scoping lives in GlobalSearch; this just hands
 * the term over and shapes the response.
 */
class SearchController extends Controller
{
    public function __construct(private readonly GlobalSearch $search) {}

    public function __invoke(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');
        // Optional single-module scope for the per-list suggestion box
        // (VSuggestSearch, Change 2); null keeps the grouped global-bar search.
        $module = $request->query('module');
        $module = is_string($module) && $module !== '' ? $module : null;

        return response()->json([
            'query' => $term,
            'groups' => $this->search->search($term, $module),
        ]);
    }
}
