<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An adoption places an animal in someone's care, so the applicant now presents a valid ID,
 * the same way volunteer applicants do (see 2026_09_08's volunteer_applications migration).
 *
 * Nullable on purpose: applications submitted before this requirement keep their rows and
 * show as "no ID on file"; new ones are made to carry an ID by the controller's validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adoption_applications', function (Blueprint $table) {
            $table->string('valid_id_type', 50)->nullable()->after('reason');
            // Stored path on the default disk; rewritten to a URL on the way out.
            $table->string('valid_id_path')->nullable()->after('valid_id_type');
        });
    }

    public function down(): void
    {
        Schema::table('adoption_applications', function (Blueprint $table) {
            $table->dropColumn(['valid_id_type', 'valid_id_path']);
        });
    }
};
