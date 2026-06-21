<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Admin seed (only if not already present)
        $adminEmail = 'prince.estrella16@gmail.com';
        $adminPassword = 'prince13161729';

        $existingAdmin = User::where('email', $adminEmail)->first();

        if (!$existingAdmin) {
            // Avoid updated_at if the users table doesn't include it
            User::insert([
                [
                    'full_name' => 'Prince Estrella',
                    'email' => $adminEmail,
                    'password' => Hash::make($adminPassword),
                    'role' => 'admin',
                    'status' => 'active',
                    'email_verified' => 1,
                    'created_at' => now(),
                ],
            ]);

        }

        // Existing demo user (no factory to avoid schema mismatch)
        $existingDemo = User::where('email', 'test@example.com')->first();
        if (!$existingDemo) {
            User::insert([
                [
                    'full_name' => 'Test User',
                    'email' => 'test@example.com',
                    'password' => Hash::make('password123'),
                    'role' => 'user',
                    'status' => 'active',
                    'email_verified' => 0,
                    'created_at' => now(),
                ],
            ]);
        }

    }
}

