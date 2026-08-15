<?php

namespace App\Http\Requests\Worker;

/**
 * A check-out from the phone. Same location rules as a PunchRequest, plus a
 * REQUIRED proof-of-work attachment: a photo of the site the worker was on, or
 * a document (delivery note, sign-off, etc.). The worker cannot close the day
 * without it — the site record must always carry the evidence.
 */
class CheckOutRequest extends PunchRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Photo 1 — REQUIRED. Image (site photo) OR a document, 8 MB cap.
            'work_attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx', 'max:8192'],
            // Photos 2 & 3 — OPTIONAL extra site photos.
            'work_attachment_2' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'work_attachment_3' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);
    }
}
