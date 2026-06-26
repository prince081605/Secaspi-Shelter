<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Why an account was suspended. Shown to the user on the suspended-login
            // screen and to admins in the Users table. `after()` is a MySQL-only hint
            // and is silently ignored on SQLite/Postgres, so this stays cross-DB safe.
            $table->text('suspension_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('suspension_reason');
        });
    }
};
