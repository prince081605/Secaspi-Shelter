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

    public function test_login_is_blocked_for_a_suspended_account_and_returns_the_reason(): void
    {
        $user = User::factory()->suspended()->create([
            'password' => Hash::make('correct-horse'),
            'suspension_reason' => 'Violated adoption policy',
        ]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertStatus(403)
            ->assertJsonPath('status', 'suspended')
            ->assertJsonPath('reason', 'Violated adoption policy')
            ->assertJsonPath('message', 'Your account has been suspended. Reason: Violated adoption policy')
            ->assertJsonMissingPath('token');
    }

    public function test_a_suspended_user_with_a_valid_token_is_rejected_on_authenticated_routes(): void
    {
        $user = User::factory()->suspended()->create(['suspension_reason' => 'Spam']);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertStatus(403)
            ->assertJsonPath('status', 'suspended')
            ->assertJsonPath('reason', 'Spam');
    }

    public function test_suspending_a_user_revokes_their_existing_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $target->createToken('auth_token');
        $this->assertSame(1, $target->tokens()->count());

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/users/{$target->id}", [
            'status' => 'suspended',
            'suspension_reason' => 'Abuse',
        ])->assertOk()->assertJsonPath('user.suspension_reason', 'Abuse');

        $this->assertSame(0, $target->fresh()->tokens()->count(), 'suspension must revoke active sessions');
    }

    public function test_reactivating_a_user_clears_the_suspension_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->suspended()->create(['suspension_reason' => 'Old reason']);

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/users/{$target->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.suspension_reason', null);
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

    public function test_password_reset_revokes_all_existing_sessions(): void
    {
        $user = User::factory()->create();
        $user->createToken('old-session-1');
        $user->createToken('old-session-2');
        $this->assertSame(2, $user->tokens()->count());

        Cache::put("password_reset:{$user->email}", ['token' => 'reset-token', 'user_id' => $user->id], 1800);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => 'reset-token',
            'password' => 'brand-new-password',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count(), 'reset must revoke pre-existing sessions');
    }

    public function test_change_password_revokes_other_sessions_but_keeps_the_current_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-pass')]);
        // The token we authenticate with (current session) plus one other session.
        $currentToken = $user->createToken('current-session')->plainTextToken;
        $user->createToken('other-session');
        $this->assertSame(2, $user->tokens()->count());

        // Authenticate via the real bearer token so currentAccessToken() resolves to a DB token.
        $this->withToken($currentToken)->postJson('/api/profile/change-password', [
            'current_password' => 'current-pass',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertOk();

        $tokens = $user->fresh()->tokens;
        $this->assertCount(1, $tokens, 'only the current session should remain');
        $this->assertSame('current-session', $tokens->first()->name);
    }

    public function test_login_is_rate_limited_after_repeated_attempts(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        // The route allows 10 attempts per minute; the 11th is throttled with a 429.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
