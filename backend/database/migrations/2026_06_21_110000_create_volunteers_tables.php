<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The original `2026_06_09_100000_create_secaspi_tables` migration defines `volunteers` and
     * `volunteer_tasks`, but that migration was never actually run against this DB (it shows as
     * "Pending" — the live schema was bootstrapped from a separate SQL import that didn't include
     * these two tables). Created here as standalone, Laravel-native tables instead, matching the
     * live DB's actual conventions (`id` PK, `created_at` only) rather than the original migration's
     * `volunteer_id`/`task_id` naming. FKs use the default RESTRICT behavior (not CASCADE) so
     * deleting a user/volunteer can't silently wipe task history.
     */
    public function up(): void
    {
        Schema::create('volunteers', function (Blueprint $table) {
            $table->id();
            // FK to users.id (bigint unsigned, per create_secaspi_tables). Using foreignId keeps
            // the column type consistent with the referenced key so a fresh `migrate` succeeds on
            // MySQL (a signed integer here mismatches and triggers errno 150).
            $table->foreignId('user_id')->constrained('users');
            $table->unique('user_id');
            $table->string('availability', 150)->nullable();
            $table->unsignedInteger('hours_rendered')->default(0);
            $table->text('performance_notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('volunteer_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volunteer_id')->constrained('volunteers');
            $table->string('task_name', 150);
            $table->enum('status', ['assigned', 'ongoing', 'completed'])->default('assigned');
            $table->date('assigned_date')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volunteer_tasks');
        Schema::dropIfExists('volunteers');
    }
};
