<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker check-out may now carry up to THREE proof-of-work photos. Photo 1 is
 * the existing (required) check_out_attachment; photos 2 and 3 are optional.
 * All server-set, never mass-assignable — stored on the private disk and
 * reachable only through the gated + audited download route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->string('check_out_attachment_2_path')->nullable()->after('check_out_attachment_name');
            $table->string('check_out_attachment_2_name')->nullable()->after('check_out_attachment_2_path');
            $table->string('check_out_attachment_3_path')->nullable()->after('check_out_attachment_2_name');
            $table->string('check_out_attachment_3_name')->nullable()->after('check_out_attachment_3_path');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table): void {
            $table->dropColumn([
                'check_out_attachment_2_path', 'check_out_attachment_2_name',
                'check_out_attachment_3_path', 'check_out_attachment_3_name',
            ]);
        });
    }
};
