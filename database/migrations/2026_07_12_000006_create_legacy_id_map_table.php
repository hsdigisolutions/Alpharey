<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps legacy VertoCRM primary keys to new-system keys so importers are
     * idempotent and the final delta import at cutover only touches changed
     * rows (DATA_MIGRATION.md §2). String ids because legacy documents use uuids.
     */
    public function up(): void
    {
        Schema::create('legacy_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 100);
            $table->string('legacy_id', 36);
            $table->string('new_id', 36);
            $table->timestamps();

            $table->unique(['entity_type', 'legacy_id']);
            $table->index(['entity_type', 'new_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_id_map');
    }
};
