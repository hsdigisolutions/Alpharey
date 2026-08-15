<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Switch the primary display language (ES/EN toggle in the header).
     * Persisted per account for authenticated users (REQUIREMENTS.md §9),
     * per session for guests (login screen toggle).
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Urdu is offered ONLY on the worker PWA; the CRM stays es/en.
        $allowed = $user !== null && $user->isWorker() ? 'es,en,ur' : 'es,en';

        $validated = $request->validate([
            'locale' => ['required', 'in:'.$allowed],
        ]);

        if ($user !== null) {
            $user->forceFill(['locale' => $validated['locale']])->save();
        }

        $request->session()->put('locale', $validated['locale']);

        return back();
    }
}
