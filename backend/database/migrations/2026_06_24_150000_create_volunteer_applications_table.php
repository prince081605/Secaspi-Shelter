<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_applications', function (Blueprint $table) {
            $table->id();
            // Indexed, no DB-level FK (the dev mock DB's users.id is int(11) from an
            // imported dump and can't be referenced by a bigint foreignId — same reason
            // as visitations/reminders). user_id is always set from the authenticated user.
            $table->unsignedBigInteger('user_id')->index();
            $table->string('availability', 150)->nullable();
            $table->text('experience')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending / approved / rejected
            $table->text('admin_notes')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_applications');
    }
};
