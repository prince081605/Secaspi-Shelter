<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rescue_reports', function (Blueprint $table) {
            // Team assignment + triage notes (Phase 6). Free-text team/person name rather than a
            // user_id FK, since there's no admin/volunteer directory UI yet to pick a real user from.
            $table->string('assigned_to', 150)->nullable()->after('status');
            $table->text('admin_notes')->nullable()->after('assigned_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rescue_reports', function (Blueprint $table) {
            $table->dropColumn(['assigned_to', 'admin_notes']);
        });
    }
};
