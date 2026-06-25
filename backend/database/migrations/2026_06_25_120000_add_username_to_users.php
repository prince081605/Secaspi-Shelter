<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add the column nullable and without the unique index first, so the backfill
        // below can populate every existing row before uniqueness is enforced.
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable()->after('full_name');
        });

        // Backfill existing accounts. generateUsername() checks the DB as it goes, so each
        // row it saves is visible to the next iteration and collisions resolve in order.
        User::orderBy('id')->get()->each(function (User $user) {
            $user->username = User::generateUsername($user->full_name);
            $user->save();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
