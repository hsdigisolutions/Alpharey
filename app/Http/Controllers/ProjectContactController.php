<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\StoreProjectContactRequest;
use App\Models\Project;
use App\Models\ProjectContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Client-side contacts for a project (supervisor / engineer / PM / other).
 * Simple CRUD, gated by `projects.edit`. A contact reached through the project
 * must belong to it (→ 404), the same nested-ownership rule the other project
 * child controllers use. company_id is set by the BelongsToCompany hook.
 */
class ProjectContactController extends Controller
{
    public function store(StoreProjectContactRequest $request, Project $project): RedirectResponse
    {
        $contact = new ProjectContact($request->validated());
        $contact->project_id = $project->id;
        $contact->save();

        return back()->with('success', __('ui.projects.contact_saved'));
    }

    public function update(StoreProjectContactRequest $request, Project $project, ProjectContact $contact): RedirectResponse
    {
        abort_unless($contact->project_id === $project->id, 404);

        $contact->update($request->validated());

        return back()->with('success', __('ui.projects.contact_saved'));
    }

    public function destroy(Project $project, ProjectContact $contact): RedirectResponse
    {
        Gate::authorize('projects.edit');

        abort_unless($contact->project_id === $project->id, 404);

        $contact->delete();

        return back()->with('success', __('ui.projects.contact_deleted'));
    }
}
