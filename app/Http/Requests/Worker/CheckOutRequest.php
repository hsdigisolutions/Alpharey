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
            // Image (site photo) OR a document. Same 8 MB cap as the selfie.
            'work_attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx', 'max:8192'],
        ]);
    }
}
