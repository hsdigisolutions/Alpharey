<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceVoiceNote;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Feature 1 — Voice / text note captured at check-out.
 *
 * STORE: called by the worker after a successful check-out; the attendance row
 * must exist and belong to them. One note per attendance row — a second call
 * updates (upsert on attendance_id).
 *
 * DOWNLOAD (worker): the worker reads their own note; served from private disk.
 * DOWNLOAD (admin):  the admin reads the note via the attendance screen —
 *   handled by AttendanceVoiceNoteDownloadController in the CRM group.
 */
class WorkerVoiceNoteController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $employee = $this->resolveEmployee($request);

        $validated = $request->validate([
            'attendance_id' => ['required', 'integer'],
            'text_note' => ['nullable', 'string', 'max:1000'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:300'],
            // mimetypes: checks client MIME directly; mimes: guesses extension from content
            // (audio/webm → 'weba' extension, not 'webm'), so mimetypes: is more reliable here.
            'audio' => ['nullable', 'file', 'mimetypes:audio/webm,audio/ogg,video/webm,audio/mp4,audio/m4a,audio/mpeg', 'max:5120'],
        ]);

        $attendance = Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->id)
            ->findOrFail((int) $validated['attendance_id']);

        $audioPath = null;
        if ($request->hasFile('audio')) {
            $file = $request->file('audio');
            $audioPath = $file->store(
                "attendance-voice-notes/{$employee->company_id}/{$employee->id}",
                'local',
            );
        }

        $note = AttendanceVoiceNote::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->firstOrNew(['attendance_id' => $attendance->id]);

        $note->employee_id = $employee->id;
        $note->company_id = $employee->company_id;
        $note->text_note = $validated['text_note'] ?? null;
        $note->duration_seconds = $validated['duration_seconds'] ?? null;

        if ($audioPath !== null) {
            // Delete old audio file if replacing.
            if ($note->audio_path && Storage::disk('local')->exists($note->audio_path)) {
                Storage::disk('local')->delete($note->audio_path);
            }
            $note->audio_path = $audioPath;
        }

        $note->save();

        return back()->with('success', __('ui.worker.note_saved'));
    }

    public function download(Request $request, AttendanceVoiceNote $voiceNote): BinaryFileResponse
    {
        $employee = $this->resolveEmployee($request);

        // Workers may only download their own notes.
        abort_unless($voiceNote->employee_id === $employee->id, 403);
        abort_unless($voiceNote->audio_path && Storage::disk('local')->exists($voiceNote->audio_path), 404);

        // Rule 10: every private-file download is audited, even a worker's own.
        app(AuditLogger::class)->log('viewed', $voiceNote, ['context' => 'worker_voice_note_download']);

        return response()->file(Storage::disk('local')->path($voiceNote->audio_path));
    }

    private function resolveEmployee(Request $request): Employee
    {
        return Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('user_id', $request->user()?->id)
            ->firstOrFail();
    }
}
