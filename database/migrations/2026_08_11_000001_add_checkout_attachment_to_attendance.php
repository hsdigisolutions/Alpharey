<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A required proof-of-work attachment captured at check-out (a site photo, or a
 * document). Stored on the private disk; the path + original name are server-set
 * (NOT fillable) and reached only through the gated, audited download route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->string('check_out_attachment_path')->nullable()->after('check_in_photo_path');
            $table->string('check_out_attachment_name')->nullable()->after('check_out_attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->dropColumn(['check_out_attachment_path', 'check_out_attachment_name']);
        });
    }
};
