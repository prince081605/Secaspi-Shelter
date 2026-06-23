<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // Postgres refuses to implicitly cast text -> json. Provide an explicit
            // USING clause. The column was just added (values are NULL), but NULLIF
            // also guards any stray empty strings so the cast can't blow up.
            DB::statement("ALTER TABLE animals ALTER COLUMN behavioral_assessment TYPE json USING (NULLIF(behavioral_assessment, '')::json)");
        } else {
            // SQLite / MySQL handle the type change directly.
            Schema::table('animals', function (Blueprint $table) {
                $table->json('behavioral_assessment')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE animals ALTER COLUMN behavioral_assessment TYPE text USING (behavioral_assessment::text)');
        } else {
            Schema::table('animals', function (Blueprint $table) {
                $table->text('behavioral_assessment')->nullable()->change();
            });
        }
    }
};
