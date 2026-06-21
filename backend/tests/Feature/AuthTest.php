<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_user_with_the_default_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'role' => 'user']);
    }

    public function test_register_cannot_self_assign_admin_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'role' => 'admin',
        ])->assertCreated();

        $this->assertSame('user', User::where('email', 'sneaky@example.com')->value('role'));
    }

    public function test_login_returns_a_token_and_rejects_a_bad_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_current_user_endpoint_returns_the_authenticated_user(): void
    {
        $this->getJson('/api/user')->assertStatus(401);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.role', 'user');
    }

    public function test_profile_update_cannot_escalate_role(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['full_name' => 'Renamed', 'role' => 'admin'])
            ->assertOk();

        $user->refresh();
        $this->assertSame('Renamed', $user->full_name);
        $this->assertSame('user', $user->role, 'role must not be mass-assignable via profile update');
    }

    public function test_password_reset_flow_changes_the_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        // Request a reset (generic response; token is not echoed outside local env).
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        // The token lives in the cache; seed a known one to exercise the reset endpoint.
        $token = 'known-reset-token';
        Cache::put("password_reset:{$user->email}", ['token' => $token, 'user_id' => $user->id], 1800);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'brand-new-password',
        ])->assertOk();

        // Old password rejected, new password works.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'old-password'])
            ->assertStatus(401);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'brand-new-password'])
            ->assertOk();
    }

    public function test_reset_with_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();
        Cache::put("password_reset:{$user->email}", ['token' => 'real-token', 'user_id' => $user->id], 1800);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => 'wrong-token',
            'password' => 'whatever-123',
        ])->assertStatus(401);
    }
}
