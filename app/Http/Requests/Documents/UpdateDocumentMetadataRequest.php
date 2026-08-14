<?php

namespace App\Http\Requests\Documents;

use App\Http\Requests\Documents\Concerns\ValidatesDocumentMetadata;
use App\Models\Document;
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
            ...$this->metadataRules($document->category, $document->type_key),
            ...$this->contactRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $document = $this->document();
            $this->afterMetadataValidation($validator, $document->category, $document->type_key);
        });
    }

    private function document(): Document
    {
        /** @var Document $document */
        $document = $this->route('document');

        return $document;
    }
}
