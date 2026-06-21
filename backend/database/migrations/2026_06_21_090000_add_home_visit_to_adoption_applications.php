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
        Schema::table('adoption_applications', function (Blueprint $table) {
            // Admin home-visit tracking (Phase 6)
            $table->enum('home_visit_status', ['not_scheduled', 'scheduled', 'completed'])->default('not_scheduled')->after('status');
            $table->date('home_visit_date')->nullable()->after('home_visit_status');
            $table->text('home_visit_notes')->nullable()->after('home_visit_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adoption_applications', function (Blueprint $table) {
            $table->dropColumn(['home_visit_status', 'home_visit_date', 'home_visit_notes']);
        });
    }
};
