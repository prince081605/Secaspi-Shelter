<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * volunteer_tasks.status was a native enum (assigned/ongoing/completed). Volunteers can
     * now self-request a task, which needs a new 'requested' value. Altering enums isn't
     * portable, so convert the column to a plain varchar and enforce allowed values in the
     * controller instead. Driver-aware to work on Postgres (prod) and MySQL (dev).
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE volunteer_tasks DROP CONSTRAINT IF EXISTS volunteer_tasks_status_check');
            DB::statement('ALTER TABLE volunteer_tasks ALTER COLUMN status TYPE varchar(20)');
            DB::statement("ALTER TABLE volunteer_tasks ALTER COLUMN status SET DEFAULT 'assigned'");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE volunteer_tasks MODIFY status VARCHAR(20) NOT NULL DEFAULT 'assigned'");
        } else {
            Schema::table('volunteer_tasks', function (Blueprint $table) {
                $table->string('status', 20)->default('assigned')->change();
            });
        }
    }

    public function down(): void
    {
        // Leave as varchar on rollback — recreating the native enum across drivers is
        // fragile and the string column is a strict superset of the old values.
    }
};
