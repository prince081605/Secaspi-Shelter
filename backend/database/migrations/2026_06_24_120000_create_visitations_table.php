<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitations', function (Blueprint $table) {
            $table->id();
            // Indexed but no DB-level FK constraint: the dev mock DB was imported from a
            // SQL dump where users.id is int(11), which a bigint foreignId can't reference
            // (errno 150). user_id is always set from the authenticated user in the
            // controller, so referential integrity is enforced in app code.
            $table->unsignedBigInteger('user_id')->index();
            $table->date('requested_date');
            $table->string('time_slot', 20); // morning / afternoon / evening
            $table->unsignedSmallInteger('num_visitors')->default(1);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending'); // pending / approved / rejected / completed
            $table->text('admin_notes')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitations');
    }
};
