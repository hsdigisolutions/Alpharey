<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentStatus;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 16 — Compliance Center: traffic-light rollup of every tracked
 * document (companies + employees). Super Admin sees all companies;
 * everyone else their own (documents carry the company scope).
 */
class ComplianceController extends Controller
{
    public function index(Request $request, DocumentStatus $status): Response
    {
        Gate::authorize('documents.view');

        $documents = $this->visibleDocuments($request)
            ->with(['documentable', 'company:id,name,province'])
            ->where('is_current', true)
            ->get();

        $summary = ['ok' => 0, 'warn' => 0, 'danger' => 0, 'neutral' => 0, 'exempt' => 0];
        $rows = [];
        $byCompany = [];

        foreach ($documents as $document) {
            [$state, $daysLeft] = $status->of($document);
            $summary[$state]++;

            $companyId = $document->company_id;
            if ($companyId !== null) {
                $byCompany[$companyId] ??= ['scores' => [], 'name' => $document->company?->name, 'province' => $document->company?->province];
                $score = $status->score($document);
                if ($score !== null) {
                    $byCompany[$companyId]['scores'][] = $score;
                }
            }

            // Filterable detail rows (worst-first sort happens client-side)
            $rows[] = [
                'id' => $document->id,
                'entity_name' => $document->documentable?->getAttribute('full_name')
                    ?? $document->documentable?->getAttribute('name'),
                'entity_type' => class_basename((string) $document->documentable_type),
                'company' => $document->company?->name,
                'category' => $document->category,
                'type_key' => $document->type_key,
                'name' => $document->name,
                'uploaded_at' => $document->created_at?->toDateString(),
                'expiry_date' => $document->expiry_date?->toDateString(),
                'days_left' => $daysLeft,
                'status' => $state,
                'is_exempt' => $document->is_exempt,
            ];
        }

        $scores = collect($byCompany)->map(fn (array $data, int $companyId): array => [
            'company_id' => $companyId,
            'name' => $data['name'],
            'province' => $data['province'],
            'percent' => $data['scores'] === []
                ? 100
                : (int) round(array_sum($data['scores']) / count($data['scores']) * 100),
        ])->values();

        return Inertia::render('Compliance', [
            'summary' => $summary,
            'companyScores' => $scores,
            'rows' => $rows,
            'canExempt' => Gate::allows('documents.approve'),
        ]);
    }

    /**
     * @return Builder<Document>
     */
    private function visibleDocuments(Request $request): Builder
    {
        $user = $request->user();

        $query = Document::query();

        // The global scope already confines non-SA users; for the SA an
        // explicit selection narrows the view, otherwise all companies.
        if ($user instanceof User && $user->isSuperAdmin()) {
            $selected = app(CurrentCompany::class)->id();

            if ($selected !== null) {
                $query->where('company_id', $selected);
            }
        }

        return $query;
    }
}
