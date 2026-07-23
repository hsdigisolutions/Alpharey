<?php

namespace App\Http\Requests\Worker;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A check-in or check-out from the phone.
 *
 * authorize() is the worker gate at the request layer — EnsureWorker already
 * guards the route, this is belt-and-braces. The coordinates are optional
 * because a refused GPS permission must not block the punch (client decision):
 * `denied` carries that, and the numbers are simply absent.
 *
 * Latitude and longitude are range-checked so a fabricated or garbled value
 * cannot be stored, but that is validation, not trust — GPS is evidence.
 */
class PunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->isWorker();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'denied' => ['nullable', 'boolean'],
            // Check-in only; the selfie. Same cap and mimes as other uploads.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    /**
     * @return array{lat: float|null, lng: float|null, accuracy: float|null, denied: bool}
     */
    public function location(): array
    {
        return [
            'lat' => $this->filled('lat') ? (float) $this->input('lat') : null,
            'lng' => $this->filled('lng') ? (float) $this->input('lng') : null,
            'accuracy' => $this->filled('accuracy') ? (float) $this->input('accuracy') : null,
            'denied' => $this->boolean('denied'),
        ];
    }
}
