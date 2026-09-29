<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Attendance: every shift a volunteer or staff member works is a time-in / time-out record,
 * and their hours rendered are now worked out from those records instead of being one number an
 * admin typed in.
 *
 * Hours already on record were typed in by hand with nothing behind them, so they move into
 * `hours_adjustment` — the part of the total not backed by attendance — and nobody's total
 * changes. From here on: hours_rendered = hours_adjustment + (attendance minutes / 60).
 * hours_rendered becomes a decimal so a 90-minute shift counts as 1.5, not 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_attendances', function (Blueprint $table) {
            $table->id();
            // Attendance belongs to the personnel record; removing the person removes their log.
            $table->foreignId('volunteer_id')->constrained('volunteers')->cascadeOnDelete();
            // dateTime, not timestamp: MySQL/MariaDB silently give a table's first NOT NULL
            // timestamp column "ON UPDATE CURRENT_TIMESTAMP", which rewrote time_in to "now"
            // every time a shift was clocked out or corrected. Stored in UTC, like every
            // other time the app writes.
            $table->dateTime('time_in');
            // Null while the person is still on duty.
            $table->dateTime('time_out')->nullable();
            // Worked minutes, fixed when the shift closes (capped for a forgotten clock-out).
            $table->unsignedInteger('minutes')->nullable();
            $table->string('notes', 255)->nullable();
            // The admin who entered or last corrected this record; null when self-recorded.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['volunteer_id', 'time_in']);
        });

        Schema::table('volunteers', function (Blueprint $table) {
            $table->decimal('hours_adjustment', 8, 2)->default(0)->after('hours_rendered');
        });

        DB::table('volunteers')->update(['hours_adjustment' => DB::raw('hours_rendered')]);

        Schema::table('volunteers', function (Blueprint $table) {
            $table->decimal('hours_rendered', 8, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('volunteers', function (Blueprint $table) {
            $table->unsignedInteger('hours_rendered')->default(0)->change();
        });

        Schema::table('volunteers', function (Blueprint $table) {
            $table->dropColumn('hours_adjustment');
        });

        Schema::dropIfExists('volunteer_attendances');
    }
};
