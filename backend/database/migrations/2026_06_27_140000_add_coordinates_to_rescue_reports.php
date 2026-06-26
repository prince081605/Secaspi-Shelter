<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rescue_reports', function (Blueprint $table) {
            // Optional pin from the reporter (or set by an admin) for the rescue map.
            $table->decimal('latitude', 10, 7)->nullable()->after('location');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('rescue_reports', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
