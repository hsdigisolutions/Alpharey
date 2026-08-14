<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Replace a document's file in place — same version number (a correction, not a
 * renewal). The old physical file is deleted; only file_path/original_name/mime/
 * size change.
 */
class ReplaceDocumentFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('documents.edit');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
        ];
    }
}
