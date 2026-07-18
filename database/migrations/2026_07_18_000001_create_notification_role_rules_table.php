<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Screen 26 Settings — per-role notification on/off matrix (Phase 8).
     *
     * A rule is (notification_type × role → enabled). Rules are group-wide,
     * not per-company: notification policy is a brand decision, and the matrix
     * lives in the Super-Admin settings area. Absence of a row means "fall back
     * to the type's coded default" (NotificationType::defaultRoles), so an
     * empty table behaves exactly as the system did before this screen existed.
     */
    public function up(): void
    {
        Schema::create('notification_role_rules', function (Blueprint $table) {
            $table->id();
            $table->string('notification_type', 40);
            $table->string('role', 20);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['notification_type', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_role_rules');
    }
};
