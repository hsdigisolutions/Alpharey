<?php

namespace App\Http\Requests\Documents;

use App\Http\Requests\Documents\Concerns\ValidatesDocumentMetadata;
use App\Models\Client;
use App\Models\Company;
use App\Models\Document;
use App\Models\Project;
use App\Models\Vendor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Edit a document's descriptive fields (metadata JSON + contact block) WITHOUT
 * touching the file, version, or the alert dates. Type comes from the bound
 * document, not from input — the whitelist can't be spoofed.
 */
class UpdateDocumentMetadataRequest extends FormRequest
{
    use ValidatesDocumentMetadata;

    public function authorize(): bool
    {
        return Gate::allows('documents.edit');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $document = $this->document();

        return [
            ...$this->metadataRules($this->entityType($document), $document->type_key),
            ...$this->contactRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $document = $this->document();
            $this->afterMetadataValidation($validator, $this->entityType($document), $document->type_key);
        });
    }

    private function document(): Document
    {
        /** @var Document $document */
        $document = $this->route('document');

        return $document;
    }

    private function entityType(Document $document): string
    {
        return match ($document->documentable_type) {
            Company::class => 'company',
            Project::class => 'project',
            Client::class => 'client',
            Vendor::class => 'vendor',
            default => 'employee',
        };
    }
}
