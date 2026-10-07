<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Team management: named teams of personnel (staff and volunteers — rows in `volunteers`), each
 * with an optional leader. Rescue reports can be assigned to a team, and a task can be given to a
 * whole team at once — each member gets their own copy, tied together by `team_batch`.
 *
 * Teams are archived, never deleted, so the rescues and tasks that name them keep their history.
 * References are plain indexed ids (no DB-level foreign keys), like the other recent tables:
 * the dev mock database's imported tables can't always be referenced by a bigint foreignId.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('purpose')->nullable();
            $table->unsignedBigInteger('leader_id')->nullable(); // a volunteers.id, always a member
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('volunteer_id')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['team_id', 'volunteer_id']);
        });

        Schema::table('rescue_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->index();
        });

        Schema::table('volunteer_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->index();
            // Shared by the copies of one team task, so its progress can be read as a whole.
            $table->string('team_batch', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_tasks', function (Blueprint $table) {
            $table->dropIndex(['team_batch']);
            $table->dropIndex(['team_id']);
            $table->dropColumn(['team_id', 'team_batch']);
        });

        Schema::table('rescue_reports', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
            $table->dropColumn('team_id');
        });

        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
