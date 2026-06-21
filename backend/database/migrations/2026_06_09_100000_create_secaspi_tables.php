<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->string('phone', 20)->nullable();
            $table->string('role', 20)->default('user');
            $table->string('status', 20)->default('active');
            $table->boolean('email_verified')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('animals', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->string('species', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->integer('age')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('size', 20)->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->string('status', 20)->default('available');
            $table->text('rescue_story')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('animal_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('animals')->cascadeOnDelete();
            $table->text('photo_url')->nullable();
            $table->boolean('is_main')->default(false);
        });

        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('animals')->cascadeOnDelete();
            $table->string('type', 20)->nullable();
            $table->text('description')->nullable();
            $table->string('vet_name', 150)->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->date('record_date')->nullable();
            $table->text('notes')->nullable();
        });

        Schema::create('vaccinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('animals')->cascadeOnDelete();
            $table->string('vaccine_name', 150)->nullable();
            $table->date('date_given')->nullable();
            $table->date('next_due')->nullable();
        });

        Schema::create('adoption_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('animal_id')->constrained('animals');
            $table->string('reference_no', 50)->nullable()->unique();
            $table->string('status', 20)->default('pending');
            $table->string('full_name', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('occupation', 100)->nullable();
            $table->string('housing_type', 100)->nullable();
            $table->text('pet_experience')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('foster_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('animal_id')->constrained('animals');
            $table->string('status', 20)->default('pending');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('reference_no', 100)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->text('proof_image')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('donated_at')->useCurrent();
        });

        Schema::create('rescue_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reporter_name', 150)->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->text('location')->nullable();
            $table->text('description')->nullable();
            $table->string('urgency', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('photo_url')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void {
        Schema::dropIfExists('rescue_reports');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('foster_applications');
        Schema::dropIfExists('adoption_applications');
        Schema::dropIfExists('vaccinations');
        Schema::dropIfExists('medical_records');
        Schema::dropIfExists('animal_photos');
        Schema::dropIfExists('animals');
        Schema::dropIfExists('users');
    }
};
