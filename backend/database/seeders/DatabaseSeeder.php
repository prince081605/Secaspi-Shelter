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
        $this->seedAdmin();
        $this->seedDemoUser();

        // Care guides (idempotent — safe to re-run on every deploy)
        $this->call(CareGuideSeeder::class);

        // Assistant FAQ knowledge base (idempotent — keyed on the question text)
        $this->call(FaqSeeder::class);
    }

    /**
     * The first admin on a brand-new database, from ADMIN_EMAIL / ADMIN_PASSWORD.
     *
     * Credentials come from the environment and never from the repo: this project is
     * public, so a committed default password would be a published admin login on every
     * deployment of it. On Render these are set in the dashboard, and the entrypoint's
     * `db:seed --force` picks them up on the next boot.
     *
     * Create-only. If the account already exists it is left completely alone, so
     * redeploying can never revert a password the admin has since changed.
     */
    protected function seedAdmin(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            // Loud, because the silent version of this is indistinguishable from a broken
            // deployment: a fresh database with nobody able to log in and nothing in the logs.
            $this->command?->warn(
                'ADMIN_EMAIL / ADMIN_PASSWORD are not set — no admin account was created. '
                .'A fresh database will have no way to log in until you set them and redeploy.'
            );

            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin {$email} already exists — left untouched.");

            return;
        }

        $fullName = env('ADMIN_NAME', 'Shelter Admin');

        // insert() rather than create(): the users table has no updated_at, and role/status
        // are deliberately not mass-assignable (see the User model). It also bypasses the
        // 'hashed' cast, which is why the hash is applied by hand here.
        User::insert([[
            'full_name' => $fullName,
            'username' => User::generateUsername($fullName),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'status' => 'active',
            'email_verified' => 1,
            'created_at' => now(),
        ]]);

        $this->command?->info("Created admin {$email}.");
    }

    /**
     * A throwaway login for local development.
     *
     * Skipped in production: the password is written in the repo, so seeding it on a live
     * site would publish a working account on the deployed shelter to anyone who reads
     * this file on GitHub.
     */
    protected function seedDemoUser(): void
    {
        if (app()->environment('production')) {
            return;
        }

        if (User::where('email', 'test@example.com')->exists()) {
            return;
        }

        User::insert([[
            'full_name' => 'Test User',
            'username' => User::generateUsername('Test User'),
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'status' => 'active',
            'email_verified' => 0,
            'created_at' => now(),
        ]]);
    }
}

