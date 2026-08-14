<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every document type now carries a point-of-contact section, and a document
     * can have MORE THAN ONE contact (client decision 2026-08-14). Replace the
     * fixed single-contact columns with a `contacts` JSON list — each entry is
     * {name, role, phone, email, notes}.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->json('contacts')->nullable()->after('metadata');
            $table->dropColumn([
                'contact_name', 'contact_phone', 'contact_email',
                'contact_emergency_phone', 'contact_notes',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('contacts');
            $table->string('contact_name')->nullable()->after('metadata');
            $table->string('contact_phone', 40)->nullable()->after('contact_name');
            $table->string('contact_email')->nullable()->after('contact_phone');
            $table->string('contact_emergency_phone', 40)->nullable()->after('contact_email');
            $table->text('contact_notes')->nullable()->after('contact_emergency_phone');
        });
    }
};
