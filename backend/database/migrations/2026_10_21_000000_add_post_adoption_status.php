<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Where an adopted animal is now (App\Services\PostAdoption::STATUSES): in its home, the family
 * out of touch, missing, deceased, rehomed, or returned to the shelter.
 *
 *  - adoption_applications.post_adoption_status: the current one. Separate from the application's
 *    own status on purpose: an adoption whose animal later died or went missing was still a
 *    completed adoption, and the adoption counts and success rate must keep saying so.
 *  - adoption_status_changes: every change, with when it happened, who recorded it and a note —
 *    the adoption's timeline. Nothing is overwritten.
 *
 * Plain indexed ids, no DB-level foreign keys, and created_at only — the same conventions as
 * the other post-adoption tables (see 2026_10_07_150000_create_post_adoption_tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adoption_applications', function (Blueprint $table) {
            $table->string('post_adoption_status', 20)->nullable()->index();
        });

        Schema::create('adoption_status_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('adoption_application_id')->index();
            $table->unsignedBigInteger('animal_id')->index();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->date('happened_on')->nullable();
            $table->string('animal_status', 20)->nullable(); // where a returned animal went
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable(); // null = recorded by the system
            $table->timestamp('created_at')->useCurrent();
        });

        // Adoptions already on record: completed ones are in their homes; returned ones were
        // returned, through an accepted return request — which becomes their timeline entry.
        DB::table('adoption_applications')->where('status', 'completed')->update(['post_adoption_status' => 'in_home']);
        DB::table('adoption_applications')->where('status', 'returned')->update(['post_adoption_status' => 'returned']);

        // Every timeline starts at the adoption itself, as new ones do (PostAdoption::start()).
        // Adoptions completed before completed_at was recorded keep an unknown date (null) rather
        // than today's — the timeline says "date not recorded" instead of something untrue.
        foreach (DB::table('adoption_applications')->whereIn('status', ['completed', 'returned'])->get(['id', 'animal_id', 'completed_at', 'created_at']) as $adoption) {
            DB::table('adoption_status_changes')->insert([
                'adoption_application_id' => $adoption->id,
                'animal_id' => $adoption->animal_id,
                'from_status' => null,
                'to_status' => 'in_home',
                'happened_on' => $adoption->completed_at ? substr((string) $adoption->completed_at, 0, 10) : null,
                'notes' => 'Adoption completed.',
                'created_at' => $adoption->completed_at ?? $adoption->created_at ?? now(),
            ]);
        }

        foreach (DB::table('adoption_returns')->where('status', 'accepted')->get() as $return) {
            DB::table('adoption_status_changes')->insert([
                'adoption_application_id' => $return->adoption_application_id,
                'animal_id' => $return->animal_id,
                'from_status' => 'in_home',
                'to_status' => 'returned',
                'happened_on' => $return->resolved_at ? substr((string) $return->resolved_at, 0, 10) : null,
                'animal_status' => $return->animal_status,
                'notes' => $return->staff_notes ?: 'Return request accepted.',
                'changed_by' => $return->resolved_by,
                'created_at' => $return->resolved_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('adoption_status_changes');

        Schema::table('adoption_applications', function (Blueprint $table) {
            $table->dropIndex(['post_adoption_status']);
            $table->dropColumn('post_adoption_status');
        });
    }
};
