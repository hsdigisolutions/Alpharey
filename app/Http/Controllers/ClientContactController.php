<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientCommunication;
use App\Models\ClientContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Client contacts (Tab 2) + communication timeline (Tab 6). Both editable
 * — this is CRM relationship data (unlike immutable project notes).
 */
class ClientContactController extends Controller
{
    public function storeContact(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('clients.edit');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $client->contacts()->create($validated);

        return back()->with('success', __('ui.clients.contact_saved'));
    }

    public function destroyContact(Client $client, ClientContact $contact): RedirectResponse
    {
        Gate::authorize('clients.edit');

        abort_unless($contact->client_id === $client->id, 404);

        $contact->delete();

        return back()->with('success', __('ui.clients.contact_deleted'));
    }

    public function storeCommunication(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('clients.edit');

        $validated = $request->validate([
            'type' => ['required', 'in:call,meeting,email,note'],
            'body' => ['required', 'string', 'max:5000'],
            'logged_at' => ['nullable', 'date'],
        ]);

        $communication = new ClientCommunication([
            'type' => $validated['type'],
            'body' => $validated['body'],
            'logged_at' => $validated['logged_at'] ?? now(),
        ]);
        $communication->client_id = $client->id;
        $communication->user_id = $request->user()?->id;
        $communication->save();

        return back()->with('success', __('ui.clients.comm_saved'));
    }
}
