<?php

namespace App\Http\Requests\Documents\Concerns;

use App\Support\DocumentTypes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Per-type metadata + contact validation shared by the store, replace-file and
 * edit-metadata requests. The rules are DERIVED from the field registry
 * (DocumentTypes::companyFields) so the whitelist can never drift from what the
 * panel renders. Fields are OPTIONAL by design — a compliance record is useful
 * half-filled (upload the file now, complete the data later), matching the
 * existing nullable issue_date/expiry_date/notes fields. The FILE requirement
 * and version behaviour live in the concrete requests.
 */
trait ValidatesDocumentMetadata
{
    /**
     * Rules for the metadata.* keys of a company document type. Only the
     * registry's own metadata keys are described; unknown keys are rejected in
     * afterMetadataValidation().
     *
     * @return array<string, array<int, mixed>>
     */
    protected function metadataRules(string $category, string $typeKey): array
    {
        if ($category !== 'company') {
            // Only company documents carry per-type metadata (this feature).
            return ['metadata' => ['nullable', 'array', 'size:0']];
        }

        $rules = ['metadata' => ['nullable', 'array']];

        foreach (DocumentTypes::companyFieldDefs($typeKey) as $field) {
            if (isset($field['column']) || $field['type'] === 'ccc') {
                continue; // column-bound dates + read-only CCC are not metadata
            }

            $key = 'metadata.'.$field['key'];
            $options = $field['options'] ?? [];

            $rules[$key] = match ($field['type']) {
                'number' => ['nullable', 'integer', 'min:0'],
                'money' => ['nullable', 'numeric', 'min:0'],
                'email' => ['nullable', 'email', 'max:150'],
                'tel' => ['nullable', 'string', 'max:40'],
                'select' => ['nullable', Rule::in($options)],
                'month_year' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
                'checkbox_group' => ['nullable', 'array'],
                'textarea' => ['nullable', 'string', 'max:2000'],
                default => ['nullable', 'string', 'max:500'],
            };

            if ($field['type'] === 'checkbox_group') {
                $rules[$key.'.*'] = [Rule::in($options)];
            }
        }

        return $rules;
    }

    /**
     * Contact block rules — only meaningful when the type carries a contact
     * section; otherwise stray contact values are rejected in the after-hook.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function contactRules(): array
    {
        return [
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'contact_emergency_phone' => ['nullable', 'string', 'max:40'],
            'contact_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Reject metadata keys outside the type whitelist, and contact values sent
     * for a type that has no contact section. Whitelist-only, per the spec.
     */
    protected function afterMetadataValidation(Validator $validator, string $category, string $typeKey): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->all();

        $metadata = $data['metadata'] ?? [];

        if (is_array($metadata) && $metadata !== []) {
            $allowed = $category === 'company' ? DocumentTypes::companyMetadataKeys($typeKey) : [];

            // 'ccc' is a reserved read-only key (value comes from the company
            // record and is stripped before storage) — tolerated on any type.
            $unknown = array_diff(array_keys($metadata), $allowed, ['ccc']);

            foreach ($unknown as $key) {
                $validator->errors()->add('metadata.'.$key, 'Unknown field for this document type.');
            }
        }

        $hasContact = $category === 'company' && ($this->typeHasContact($typeKey));

        if (! $hasContact) {
            foreach (['contact_name', 'contact_phone', 'contact_email', 'contact_emergency_phone', 'contact_notes'] as $key) {
                if (filled($data[$key] ?? null)) {
                    $validator->errors()->add($key, 'This document type has no contact section.');
                }
            }
        }
    }

    protected function typeHasContact(string $typeKey): bool
    {
        return (DocumentTypes::companyFields()[$typeKey]['contact'] ?? false) === true;
    }
}
