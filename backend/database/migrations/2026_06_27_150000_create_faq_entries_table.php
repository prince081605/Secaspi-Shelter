<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The trainable knowledge base for the assistant. Each row is a Q&A the admin curates;
        // the FaqMatcher ranks incoming questions against question + tags by similarity.
        Schema::create('faq_entries', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->string('tags')->nullable();           // extra keywords to boost matching
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('hits')->default(0);  // times this entry answered a question
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_entries');
    }
};
