<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // For SQLite, we need to recreate the column as JSON. For Postgres, we can cast.
        Schema::table('animals', function (Blueprint $table) {
            $table->json('behavioral_assessment')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('animals', function (Blueprint $table) {
            $table->text('behavioral_assessment')->nullable()->change();
        });
    }
};
