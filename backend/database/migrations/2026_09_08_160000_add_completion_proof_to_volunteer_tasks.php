<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A volunteer finishing a task had no way to say so — only an admin could move it along, from a
 * dashboard that showed no evidence the work had happened. The volunteer now sends a photo of
 * the finished task, which parks it in a new 'submitted' status until an admin looks at the
 * photo and completes it.
 *
 * status is already a plain varchar (see the 2026_06_24 alter migration, which dropped the
 * native enum precisely so new values would not need a schema change), so 'submitted' needs
 * nothing here beyond the controller's allow-list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_tasks', function (Blueprint $table) {
            $table->string('proof_path')->nullable()->after('assigned_date');
            $table->text('proof_note')->nullable()->after('proof_path');
            $table->timestamp('proof_submitted_at')->nullable()->after('proof_note');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_tasks', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'proof_note', 'proof_submitted_at']);
        });
    }
};
