<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The ID number turned out to be redundant: the photo already shows it, and asking an applicant
 * to retype it only created a second copy of a sensitive number to store and keep straight. The
 * type of ID plus the photo is what a reviewer actually uses.
 *
 * A separate migration rather than an edit to the one that added the column — that one has
 * already run, so amending it would leave the column in place wherever it was applied while
 * fresh databases never got it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_applications', function (Blueprint $table) {
            $table->dropColumn('valid_id_number');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_applications', function (Blueprint $table) {
            $table->string('valid_id_number', 100)->nullable()->after('valid_id_type');
        });
    }
};
