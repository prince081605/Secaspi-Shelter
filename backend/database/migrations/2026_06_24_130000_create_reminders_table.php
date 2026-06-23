<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            // Indexed, no DB-level FK (see visitations migration: the dev mock DB's id
            // columns are int(11) from an imported dump and can't be referenced by a
            // bigint foreignId). Cleaned up in app code when the source record is removed.
            $table->unsignedBigInteger('animal_id')->index();
            // Polymorphic source record (e.g. a vaccination). Nullable so manually-created
            // reminders are possible too.
            $table->string('remindable_type')->nullable();
            $table->unsignedBigInteger('remindable_id')->nullable();
            $table->string('title');
            $table->date('reminder_date')->index();
            $table->string('status', 20)->default('pending'); // pending / sent / completed
            $table->timestamp('created_at')->useCurrent();

            $table->index(['remindable_type', 'remindable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
