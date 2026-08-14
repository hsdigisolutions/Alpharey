<?php

namespace App\Http\Requests\Documents;

use App\Http\Requests\Documents\Concerns\ValidatesDocumentMetadata;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Upload a document (new type or a NEW VERSION of an existing one). Replaces the
 * former inline validation in DocumentController::store. Per-type metadata +
 * contact validation is derived from the field registry (whitelist only).
 */
class StoreDocumentRequest extends FormRequest
{
    use ValidatesDocumentMetadata;

    public function authorize(): bool
    {
        return Gate::allows('documents.upload');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $category = (string) $this->input('category');
        $typeKey = (string) $this->input('type_key');

        return [
            'entity_type' => ['required', 'in:employee,company,project'],
            'entity_id' => ['required', 'integer'],
            'type_key' => ['required', 'string', 'max:60'],
            'category' => ['required', 'in:personal,employment,prevencion,custom,company,project'],
            'name' => ['nullable', 'string', 'max:150'],
            'file' => ['nullable', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
            'has_flag' => ['nullable', 'boolean'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->metadataRules($category, $typeKey),
            ...$this->contactRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->afterMetadataValidation(
                $validator,
                (string) $this->input('category'),
                (string) $this->input('type_key'),
            );
        });
    }
}
