<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Admin user search must be case-insensitive on every database engine.
     *
     * Plain `LIKE` is case-insensitive on MySQL/SQLite but case-SENSITIVE on PostgreSQL
     * (production), so UserController normalises both sides with LOWER(). We flip SQLite into
     * case-sensitive LIKE here so this test reproduces Postgres behaviour — otherwise the
     * default (case-insensitive) SQLite LIKE would pass even the buggy version and never catch
     * the regression.
     */
    public function test_admin_user_search_is_case_insensitive_on_all_engines(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA case_sensitive_like = ON;');
        }

        Sanctum::actingAs(User::factory()->admin()->create());

        $target = User::factory()->create([
            'full_name' => 'Maria Santos',
            'email' => 'Maria.Santos@Example.com',
        ]);

        // Lowercase query finds the mixed-case name.
        $this->getJson('/api/admin/users?q=maria')
            ->assertOk()
            ->assertJsonFragment(['id' => $target->id]);

        // Uppercase query finds the mixed-case email/name.
        $this->getJson('/api/admin/users?q=SANTOS')
            ->assertOk()
            ->assertJsonFragment(['id' => $target->id]);

        // A non-matching query returns the target nowhere in the results.
        $this->getJson('/api/admin/users?q=zzz-no-such-user')
            ->assertOk()
            ->assertJsonMissing(['id' => $target->id]);
    }
}
