<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * New module, distinct from `rescue_reports` (per explicit choice): covers intake triage for
     * rescue/owner-surrender/stray cases, with its own document uploads and assessment workflow,
     * ending in an optional conversion into a real `animals` record. `converted_animal_id` is a
     * plain nullable column with no FK constraint (not `RESTRICT`, not `CASCADE`) so that deleting
     * either an intake or the animal it produced never blocks or cascades unexpectedly — same
     * caution learned from the Animal-delete incident earlier in this phase.
     */
    public function up(): void
    {
        Schema::create('intakes', function (Blueprint $table) {
            $table->id();
            $table->enum('intake_type', ['rescue', 'owner_surrender', 'stray']);
            $table->string('reporter_name', 150)->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->text('location')->nullable();
            $table->string('animal_name', 100)->nullable();
            $table->string('species', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->string('estimated_age', 50)->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'under_assessment', 'approved', 'converted', 'rejected'])->default('pending');
            $table->text('assessment_notes')->nullable();
            $table->string('assessed_by', 150)->nullable();
            $table->date('assessment_date')->nullable();
            $table->integer('converted_animal_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('intake_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained('intakes')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_documents');
        Schema::dropIfExists('intakes');
    }
};
