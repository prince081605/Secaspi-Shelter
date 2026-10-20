<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The all-powerful `admin` role is gone, split into Desk / Data / System Admin (docs/ADMIN_ROLES.md).
 * Every existing admin becomes a System Admin — the mapping the shelter approved. Each of them
 * did user management, and only a System Admin can hand out roles, so this is what lets them make
 * staff members Desk and Data Admins afterwards. The converted accounts are written to the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        $admins = DB::table('users')->where('role', 'admin')->get(['id', 'email']);

        foreach ($admins as $admin) {
            Log::info("Admin role split: user #{$admin->id} ({$admin->email}) is now a System Admin.");
        }

        DB::table('users')->where('role', 'admin')->update(['role' => 'system_admin']);
    }

    public function down(): void
    {
        DB::table('users')->whereIn('role', ['desk_admin', 'data_admin', 'system_admin'])->update(['role' => 'admin']);
    }
};
