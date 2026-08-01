<?php

namespace App\Http\Controllers;

use App\Models\AttendanceVoiceNote;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin download of a worker's voice note. Gate-checked and audited.
 * Workers download their own notes through WorkerVoiceNoteController.
 */
class AttendanceVoiceNoteController extends Controller
{
    public function download(AttendanceVoiceNote $voiceNote): BinaryFileResponse
    {
        Gate::authorize('attendance.view');

        abort_unless($voiceNote->audio_path && Storage::disk('local')->exists($voiceNote->audio_path), 404);

        app(AuditLogger::class)->log('viewed', $voiceNote, ['context' => 'voice_note_download']);

        return response()->file(Storage::disk('local')->path($voiceNote->audio_path));
    }
}
