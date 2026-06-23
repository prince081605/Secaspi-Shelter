<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_guides', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->enum('species', ['dog', 'cat']);
            $table->json('breed_keywords')->nullable();
            $table->enum('age_range', ['puppy', 'adult', 'senior'])->nullable();
            $table->json('behavioral_keywords')->nullable();
            $table->enum('category', ['diet', 'exercise', 'behavioral', 'health', 'grooming', 'training']);
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_guides');
    }
};
