<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named `app_notifications` (not `notifications`) because `User` uses Laravel's `Notifiable`
     * trait, which assumes a polymorphic `notifications` table (uuid id, notifiable_type/id) if
     * the `database` channel is ever used. Keeping a separate table avoids a schema collision.
     */
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            // users.id is a signed `int(11)` on the live DB (not bigint unsigned), so the FK
            // column must match that exact type or MySQL refuses to create the constraint
            // (errno 150) — same gotcha noted in the volunteers migration.
            $table->integer('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title', 150);
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
