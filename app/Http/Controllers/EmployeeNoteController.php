<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Screen 06 Tab 5 — Notas. Notes are editable/deletable per spec
 * (unlike project notes, which are immutable); every change is audited.
 */
class EmployeeNoteController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('employees.edit');

        $validated = $request->validate([
            'type' => ['required', 'in:general,reminder,issue,call'],
            'body' => ['required', 'string', 'max:5000'],
            'noted_at' => ['nullable', 'date'],
        ]);

        $note = new EmployeeNote([
            'type' => $validated['type'],
            'body' => $validated['body'],
            'noted_at' => $validated['noted_at'] ?? now(),
        ]);
        $note->employee_id = $employee->id;
        $note->company_id = $employee->company_id;
        $note->user_id = $request->user()?->id;
        $note->save();

        return back()->with('success', __('ui.employees.note_saved'));
    }

    public function destroy(Request $request, Employee $employee, EmployeeNote $note): RedirectResponse
    {
        Gate::authorize('employees.edit');

        abort_unless($note->employee_id === $employee->id, 404);

        $note->delete();

        return back()->with('success', __('ui.employees.note_deleted'));
    }
}
