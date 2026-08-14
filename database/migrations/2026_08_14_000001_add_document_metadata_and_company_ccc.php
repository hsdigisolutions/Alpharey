<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Smart document detail: per-type fields (JSON metadata) + a point-of-contact
     * block on the documents that carry one (RC / Accidentes / SPA / Mutua). The
     * version model already exists (version + is_current + soft-deletes); this only
     * adds the descriptive payload. CCC (Código Cuenta Cotización) is a company-level
     * attribute shown read-only on the docs that reference it — entered once, never
     * re-typed (client decision Q3, 2026-08-14).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->json('metadata')->nullable()->after('notes');
            $table->string('contact_name')->nullable()->after('metadata');
            $table->string('contact_phone', 40)->nullable()->after('contact_name');
            $table->string('contact_email')->nullable()->after('contact_phone');
            $table->string('contact_emergency_phone', 40)->nullable()->after('contact_email');
            $table->text('contact_notes')->nullable()->after('contact_emergency_phone');
        });

        Schema::table('companies', function (Blueprint $table): void {
            // Código Cuenta de Cotización — the Social Security account code, shown
            // read-only on Mutua / TGSS / ITA / RNT / RLC documents.
            $table->string('ccc', 30)->nullable()->after('cif');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn([
                'metadata', 'contact_name', 'contact_phone',
                'contact_email', 'contact_emergency_phone', 'contact_notes',
            ]);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('ccc');
        });
    }
};
