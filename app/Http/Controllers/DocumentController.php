<?php

namespace App\Http\Controllers;

use App\Http\Requests\Documents\ReplaceDocumentFileRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentMetadataRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Models\Vendor;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentCompany;
use App\Support\DocumentTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
    public function store(StoreDocumentRequest $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validated();

        $entity = $this->resolveEntity($validated['entity_type'], (int) $validated['entity_id']);
        $this->assertKnownType($validated['entity_type'], $validated['category'], $validated['type_key']);

        // Owning company, resolved UP FRONT so the version-chain lookup is
        // deterministic (session-independent). Company owns its own docs;
        // employee/project inherit; shared entities (client/vendor) take the
        // ACTING company, so each company keeps its own paperwork for the
        // shared record (same rule as invoices) — and must have one selected.
        $companyId = match (true) {
            $entity instanceof Company => $entity->id,
            $entity instanceof Client, $entity instanceof Vendor => app(CurrentCompany::class)->id(),
            default => $entity->getAttribute('company_id'),
        };
        abort_if(
            $companyId === null && ($entity instanceof Client || $entity instanceof Vendor),
            422,
            'Select a company before adding documents to a shared record.',
        );

        $path = null;
        $file = $request->file('file');

        if ($file !== null) {
            // Randomized stored name; the original stays as display metadata
            $folder = $validated['entity_type'].'s/'.$entity->getKey().'/documents';
            $path = $file->storeAs($folder, Str::random(40).'.'.$file->getClientOriginalExtension(), 'local');
        }

        // Versioning: the previous current row of this slot (THIS company's
        // chain) becomes history. Scoped by company_id explicitly so a Super
        // Admin browsing all companies can't demote another company's version.
        $previous = Document::query()->withoutGlobalScope(CompanyScope::class)
            ->where('documentable_type', $entity->getMorphClass())
            ->where('documentable_id', $entity->getKey())
            ->where('type_key', $validated['type_key'])
            ->where('company_id', $companyId)
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
            'metadata' => $this->cleanMetadata($validated['metadata'] ?? null),
            'contacts' => $this->cleanContacts($validated['contacts'] ?? null),
        ]);
        $document->documentable()->associate($entity);
        $document->company_id = $companyId;
        $document->uploaded_by = $request->user()?->id;
        $document->version = $previous !== null ? $previous->version + 1 : 1;
        $document->setAttribute('file_path', $path);

        // Atomic version swap: the new row goes current and the prior one steps
        // down together, so a failure between them can never leave a slot with
        // two current rows. The file write stays outside — a stray file is
        // harmless, a half-done swap is not.
        DB::transaction(function () use ($document, $previous): void {
            $document->save();

            if ($previous !== null) {
                // is_current is not mass-assignable — set it directly
                $previous->is_current = false;
                $previous->save();
            }
        });

        $audit->log('uploaded', $document, null, null, $document->type_key, 'documents');

        return back()->with('success', __('ui.documents.saved'));
    }

    /**
     * Replace the file on an existing version — a correction, NOT a renewal, so
     * the version number is untouched. The prior physical file is deleted.
     */
    public function replace(ReplaceDocumentFileRequest $request, Document $document, AuditLogger $audit): RedirectResponse
    {
        $this->assertCompanyDocumentAccess($document);

        $file = $request->file('file');
        abort_if($file === null, 422);

        $entityType = $this->entityTypeFor($document);
        $folder = $entityType.'s/'.$document->documentable_id.'/documents';
        $newPath = $file->storeAs($folder, Str::random(40).'.'.$file->getClientOriginalExtension(), 'local');

        $oldPath = $document->getAttribute('file_path');

        $document->fill([
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
        $document->setAttribute('file_path', $newPath); // not mass-assignable
        $document->save();

        // Old file removed only after the row points at the new one.
        if ($oldPath !== null && Storage::disk('local')->exists($oldPath)) {
            Storage::disk('local')->delete($oldPath);
        }

        $audit->log('updated', $document, null, null, $document->type_key, 'documents');

        return back()->with('success', __('ui.documents.saved'));
    }

    /**
     * Edit the descriptive fields (metadata JSON + contact block) + correct the
     * alert dates in place, without touching the file or the version.
     */
    public function updateMetadata(UpdateDocumentMetadataRequest $request, Document $document, AuditLogger $audit): RedirectResponse
    {
        $this->assertCompanyDocumentAccess($document);

        $validated = $request->validated();

        $data = [
            'metadata' => $this->cleanMetadata($validated['metadata'] ?? null),
            'contacts' => $this->cleanContacts($validated['contacts'] ?? null),
        ];

        // In-place date correction (client 2026-09) — the 90/60/30 alert engine
        // reads these columns, so a corrected date re-grades at once. Only touch
        // them when the form actually submitted them (the panel always does; a
        // metadata-only caller leaves the dates untouched, never wiping them).
        if ($request->exists('issue_date')) {
            $data['issue_date'] = $validated['issue_date'] ?? null;
        }
        if ($request->exists('expiry_date')) {
            $data['expiry_date'] = $validated['expiry_date'] ?? null;
        }

        $document->update($data);

        $audit->log('updated', $document, null, null, $document->type_key, 'documents');

        return back()->with('success', __('ui.documents.saved'));
    }

    /**
     * Drop empty metadata values so a blank form stores null, not a bag of
     * empty strings. CCC is never stored — it's read-only from the company.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function cleanMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        unset($metadata['ccc']);

        $clean = array_filter(
            $metadata,
            static fn ($value): bool => $value !== null && $value !== '' && $value !== [],
        );

        return $clean === [] ? null : $clean;
    }

    /**
     * Drop fully-empty contact rows and non-field keys so an untouched
     * "add contact" line doesn't persist as a blank entry.
     *
     * @param  array<int, mixed>|null  $contacts
     * @return list<array<string, string>>|null
     */
    private function cleanContacts(?array $contacts): ?array
    {
        if ($contacts === null) {
            return null;
        }

        $allowed = ['name', 'role', 'phone', 'email', 'notes'];

        $clean = [];

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $row = [];
            foreach ($allowed as $key) {
                $value = $contact[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $row[$key] = trim($value);
                }
            }

            if ($row !== []) {
                $clean[] = $row;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * The upload folder prefix for a document's owner (employee|company|project).
     */
    private function entityTypeFor(Document $document): string
    {
        return match ($document->documentable_type) {
            Company::class => 'company',
            Project::class => 'project',
            Client::class => 'client',
            Vendor::class => 'vendor',
            default => 'employee',
        };
    }

    /**
     * Company official records are admin-only — the flat documents.* grant a
     * Company Admin might give a Manager (for employee paperwork) must NOT reach
     * the company's own compliance files. Enforced on write in resolveEntity();
     * mirrored here on read/delete/exempt.
     */
    public function assertCompanyDocumentAccess(Document $document): void
    {
        if ($document->documentable_type !== Company::class) {
            return;
        }

        $user = request()->user();
        abort_if($user === null || (! $user->isSuperAdmin() && ! $user->isCompanyAdmin()), 403);
    }

    public function download(Request $request, Document $document, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('documents.download');
        $this->assertCompanyDocumentAccess($document);

        $path = $document->getAttribute('file_path');

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $audit->log('downloaded', $document, null, null, $document->type_key, 'documents');

        return Storage::disk('local')->download($path, $document->original_name ?? 'documento');
    }

    /**
     * Serve the file INLINE (not as an attachment) for the in-panel preview —
     * an image renders in a lightbox, a PDF in an embedded viewer. Same gate +
     * access check + audit as a download (seeing the content is a download), but
     * only for the previewable types (image / PDF); anything else 404s so the UI
     * falls back to the download button.
     */
    public function preview(Request $request, Document $document, AuditLogger $audit): BinaryFileResponse
    {
        Gate::authorize('documents.download');
        $this->assertCompanyDocumentAccess($document);

        $path = $document->getAttribute('file_path');

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);
        abort_unless($this->isPreviewable($document->mime), 404);

        $audit->log('viewed', $document, null, null, $document->type_key, 'documents');

        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $document->mime ?? 'application/octet-stream',
        ]);
        // Explicit INLINE disposition — the whole point of preview vs download.
        $response->setContentDisposition('inline', $document->original_name ?? 'documento');

        return $response;
    }

    /**
     * Only images and PDFs render inline; every other type is download-only.
     */
    private function isPreviewable(?string $mime): bool
    {
        return $mime !== null
            && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('documents.delete');
        $this->assertCompanyDocumentAccess($document);

        $document->delete(); // soft delete — metadata + audit survive

        return back()->with('success', __('ui.documents.deleted'));
    }

    /**
     * Toggle exempt status (Compliance Center action).
     */
    public function exempt(Request $request, Document $document): RedirectResponse
    {
        Gate::authorize('documents.approve');
        $this->assertCompanyDocumentAccess($document);

        $document->update(['is_exempt' => ! $document->is_exempt]);

        return back()->with('success', __('ui.documents.saved'));
    }

    private function resolveEntity(string $type, int $id): Model
    {
        if ($type === 'company') {
            // Company has no tenancy scope (it IS the tenant). Company
            // documents are the official records surfaced only on the
            // Super-Admin-only Companies screen, so operating on them
            // requires an admin role (not just the documents.* module
            // permission a Company Admin might grant a custom user), and
            // Company Admins are confined to their own company.
            $user = request()->user();

            abort_if(
                $user === null || (! $user->isSuperAdmin() && ! $user->isCompanyAdmin()),
                403,
            );

            abort_if(
                ! $user->isSuperAdmin() && $user->company_id !== $id,
                404,
            );

            return Company::query()->findOrFail($id);
        }

        if ($type === 'project') {
            // Project carries the company scope — out-of-company ids 404 here
            return Project::query()->findOrFail($id);
        }

        if ($type === 'client') {
            // Shared record; the document takes the acting company's id on save,
            // so visibility is still tenant-scoped.
            return Client::query()->findOrFail($id);
        }

        if ($type === 'vendor') {
            return Vendor::query()->findOrFail($id);
        }

        // Employee carries the company scope — out-of-company ids 404 here
        return Employee::query()->findOrFail($id);
    }

    private function assertKnownType(string $entityType, string $category, string $typeKey): void
    {
        if ($category === 'custom') {
            return; // free label slots are allowed by design
        }

        $known = match ($entityType) {
            'company' => DocumentTypes::companyKeys(),
            'project' => DocumentTypes::projectKeys(),
            'client' => DocumentTypes::clientKeys(),
            'vendor' => DocumentTypes::vendorKeys(),
            default => DocumentTypes::employeeKeys(),
        };

        abort_unless(in_array($typeKey, $known, true), 422, 'Unknown document type.');
    }
}
