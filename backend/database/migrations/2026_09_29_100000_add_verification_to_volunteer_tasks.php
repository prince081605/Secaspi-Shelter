<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Completing a task is now an admin's verification of the proof the volunteer or staff member
 * sent, so the task records who verified it and when — both shown back to the person who did
 * the work. nullOnDelete: removing the admin's account must not take the task history with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_tasks', function (Blueprint $table) {
            $table->foreignId('verified_by')->nullable()->after('proof_submitted_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
        });
    }
};
