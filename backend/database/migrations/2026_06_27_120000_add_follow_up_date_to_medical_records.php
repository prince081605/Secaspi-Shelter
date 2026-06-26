<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            // Optional date for a recheck/follow-up (e.g. post-surgery checkup, treatment review).
            // When set, a health reminder is auto-created — see MedicalRecordController.
            $table->date('follow_up_date')->nullable()->after('record_date');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn('follow_up_date');
        });
    }
};
