<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_call_logs', function (Blueprint $table): void {
            // Voice note recorded in-browser (or uploaded as audio)
            $table->string('voice_note_path')->nullable()->after('follow_up_date');
            $table->string('voice_note_label', 255)->nullable()->after('voice_note_path');

            // File attachment (call recording, document, image, pdf)
            $table->string('attachment_path')->nullable()->after('voice_note_label');
            $table->string('attachment_original_name', 255)->nullable()->after('attachment_path');
            $table->string('attachment_label', 255)->nullable()->after('attachment_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('employee_call_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'voice_note_path',
                'voice_note_label',
                'attachment_path',
                'attachment_original_name',
                'attachment_label',
            ]);
        });
    }
};
