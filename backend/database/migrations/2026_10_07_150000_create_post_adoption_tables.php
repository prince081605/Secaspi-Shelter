<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Post-adoption care: what happens after an adoption is marked Completed.
 *
 *  - adoption_follow_ups: the check-ins staff make (1 week, 1 month, 3 months, 6 months after).
 *  - adoption_updates: news the adopter sends from their dashboard, optionally offered as a
 *    public "Happy Tails" story (published only once staff approve it).
 *  - adoption_returns: an adopter asking to bring the animal back.
 *
 * References are plain indexed ids, not DB-level foreign keys — same as reminders and
 * visitations, because the dev mock database's id columns (int(11), from an imported dump)
 * can't be referenced by a bigint foreignId. Event times are dateTime, not timestamp, so
 * MariaDB never rewrites them on update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adoption_applications', function (Blueprint $table) {
            $table->dateTime('completed_at')->nullable();
        });

        Schema::create('adoption_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('adoption_application_id')->index();
            $table->unsignedBigInteger('animal_id')->index();
            $table->string('label', 40); // "1 month"
            $table->date('due_date')->index();
            $table->string('status', 20)->default('pending'); // pending | done | cancelled
            $table->string('contact_method', 20)->nullable();
            $table->string('wellbeing', 20)->nullable(); // doing_well | needs_support | concern
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('adopter_notified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('adoption_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('adoption_application_id')->index();
            $table->unsignedBigInteger('animal_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('follow_up_id')->nullable(); // the check-in it answered
            $table->string('wellbeing', 20); // doing_well | some_concerns | need_help
            $table->text('message');
            $table->json('photo_paths')->nullable();
            // Happy Tails: only ever public when the adopter shared it AND staff approved it.
            $table->boolean('share_publicly')->default(false);
            $table->string('story_status', 20)->nullable(); // pending | approved | declined
            $table->unsignedBigInteger('story_reviewed_by')->nullable();
            $table->dateTime('story_reviewed_at')->nullable();
            $table->dateTime('read_at')->nullable(); // staff have seen it
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('adoption_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('adoption_application_id')->index();
            $table->unsignedBigInteger('animal_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending | accepted | declined
            $table->string('animal_status', 20)->nullable(); // where the animal went, when accepted
            $table->text('staff_notes')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adoption_returns');
        Schema::dropIfExists('adoption_updates');
        Schema::dropIfExists('adoption_follow_ups');

        Schema::table('adoption_applications', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
