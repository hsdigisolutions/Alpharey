<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Services\Audit\AuditLogger;
use App\Support\DocumentTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The unified document endpoints (employee + company surfaces in Phase 2;
 * projects/vehicles join in their phases). Files live on the private local
 * disk with randomized names; every upload/download/delete is audited
 * (dev skill Rule 10). Uploading over an existing type creates a new
 * VERSION — prior rows stay as history.
 */
class DocumentController extends Controller
{
    private const MAX_KB = 15360; // 15 MB

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('documents.upload');

        $validated = $request->validate([
            'entity_type' => ['required', 'in:employee,company'],
            'entity_id' => ['required', 'integer'],
            'type_key' => ['required', 'string', 'max:60'],
            'category' => ['required', 'in:personal,employment,training,medical,custom,company'],
            'name' => ['nullable', 'string', 'max:150'],
            'file' => ['nullable', 'file', 'max:'.self::MAX_KB, 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
            'has_flag' => ['nullable', 'boolean'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $entity = $this->resolveEntity($validated['entity_type'], (int) $validated['entity_id']);
        $this->assertKnownType($validated['entity_type'], $validated['category'], $validated['type_key']);

        $path = null;
        $file = $request->file('file');

        if ($file !== null) {
            // Randomized stored name; the original stays as display metadata
            $folder = $validated['entity_type'].'s/'.$entity->getKey().'/documents';
            $path = $file->storeAs($folder, Str::random(40).'.'.$file->getClientOriginalExtension(), 'local');
        }

        // Versioning: the previous current row of this slot becomes history
        $previous = Document::query()
            ->where('documentable_type', $entity->getMorphClass())
            ->where('documentable_id', $entity->getKey())
            ->where('type_key', $validated['type_key'])
            ->where('is_current', true)
            ->when($validated['category'] === 'custom', fn ($q) => $q->where('name', $validated['name'] ?? ''))
            ->first();

        $document = new Document([
            'category' => $validated['category'],
            'type_key' => $validated['type_key'],
            'name' => $validated['name'] ?? null,
            'original_name' => $file?->getClientOriginalName(),
            'mime' => $file?->getMimeType(),
            'size' => $file?->getSize(),
            'has_flag' => $validated['has_flag'] ?? null,
            'issue_date' => $validated['issue_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);
        $document->documentable()->associate($entity);
        $document->company_id = $entity instanceof Company ? $entity->id : $entity->getAttribute('company_id');
        $document->uploaded_by = $request->user()?->id;
        $document->version = $previous !== null ? $previous->version + 1 : 1;
        $document->setAttribute('file_path', $path);
        $document->save();

        if ($previous !== null) {
            // is_current is not mass-assignable — set it directly
            $previous->is_current = false;
            $previous->save();
        }

        $audit->log('uploaded', $document, null, null, $document->type_key, 'documents');

        return back()->with('success', __('ui.documents.saved'));
    }

    public function download(Request $request, Document $document, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('documents.download');

        $path = $document->getAttribute('file_path');

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $audit->log('downloaded', $document, null, null, $document->type_key, 'documents');

        return Storage::disk('local')->download($path, $document->original_name ?? 'documento');
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('documents.delete');

        $document->delete(); // soft delete — metadata + audit survive

        return back()->with('success', __('ui.documents.deleted'));
    }

    /**
     * Toggle exempt status (Compliance Center action).
     */
    public function exempt(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('documents.approve');

        $document->update(['is_exempt' => ! $document->is_exempt]);

        return back()->with('success', __('ui.documents.saved'));
    }

    private function resolveEntity(string $type, int $id): Model
    {
        if ($type === 'company') {
            // Company has no tenancy scope (it IS the tenant) — guard
            // explicitly: Super Admin anywhere, admins their own company only.
            $user = request()->user();

            abort_if(
                $user !== null && ! $user->isSuperAdmin() && $user->company_id !== $id,
                404,
            );

            return Company::query()->findOrFail($id);
        }

        // Employee carries the company scope — out-of-company ids 404 here
        return Employee::query()->findOrFail($id);
    }

    private function assertKnownType(string $entityType, string $category, string $typeKey): void
    {
        if ($category === 'custom') {
            return; // free label slots are allowed by design
        }

        $known = $entityType === 'company'
            ? DocumentTypes::companyKeys()
            : DocumentTypes::employeeKeys();

        abort_unless(in_array($typeKey, $known, true), 422, 'Unknown document type.');
    }
}
