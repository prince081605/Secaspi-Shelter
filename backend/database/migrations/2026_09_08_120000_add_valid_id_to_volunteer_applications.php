<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Volunteers handle animals and meet the public at adoption events, so the shelter records who
 * they are rather than taking a name on trust. An applicant now presents a valid or school ID.
 *
 * Nullable on purpose: applications submitted before this requirement existed keep their rows,
 * and the admin panel renders them as "no ID on file" rather than breaking. New applications
 * are made to carry one by the controller's validation, not by the schema.
 *
 * `valid_id_*` rather than `id_*` — a bare `id_type` sitting next to Eloquent's own `id` reads
 * as "the type of the id column".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_applications', function (Blueprint $table) {
            $table->string('valid_id_type', 50)->nullable()->after('reason');
            $table->string('valid_id_number', 100)->nullable()->after('valid_id_type');
            // The stored path on the public disk. Rewritten to a URL on the way out, the way
            // ExpenseController pairs receipt_path with receipt_url.
            $table->string('valid_id_path')->nullable()->after('valid_id_number');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_applications', function (Blueprint $table) {
            $table->dropColumn(['valid_id_type', 'valid_id_number', 'valid_id_path']);
        });
    }
};
