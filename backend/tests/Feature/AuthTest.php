<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            'password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('user.role', 'user'); // response must report the default role, not null

        // A new account starts unverified until it clicks the emailed link.
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'role' => 'user', 'email_verified' => false]);
    }

    public function test_register_requires_a_matching_password_confirmation(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different456',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);
    }

    public function test_register_cannot_self_assign_admin_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertCreated();

        $this->assertSame('user', User::where('email', 'sneaky@example.com')->value('role'));
    }

    public function test_email_verification_marks_the_account_verified(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Verify Me',
            'email' => 'verify@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('user.email_verified', 0);

        $user = User::where('email', 'verify@example.com')->first();
        $this->assertNotNull($user->email_verification_token, 'a token should be issued at registration');

        $this->postJson('/api/verify-email', [
            'email' => 'verify@example.com',
            'token' => $user->email_verification_token,
        ])->assertOk();

        $user->refresh();
        $this->assertTrue((bool) $user->email_verified);
        $this->assertNull($user->email_verification_token, 'the token should be cleared after use');
    }

    public function test_registration_sends_the_verification_email_via_brevo_when_configured(): void
    {
        // In production a Brevo API key is set; the app must send over Brevo's HTTPS API rather
        // than SMTP (which Render's free tier blocks).
        config()->set('services.brevo.key', 'test-brevo-key');
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'abc'], 201)]);

        $this->postJson('/api/register', [
            'name' => 'Brevo User',
            'email' => 'brevo@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.brevo.com/v3/smtp/email')
                && $request['to'][0]['email'] === 'brevo@example.com'
                && $request->hasHeader('api-key', 'test-brevo-key');
        });
    }

    public function test_email_verification_rejects_a_bad_token(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Bad Token',
            'email' => 'badtoken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->postJson('/api/verify-email', [
            'email' => 'badtoken@example.com',
            'token' => 'not-the-real-token',
        ])->assertStatus(401);

        $this->assertFalse((bool) User::where('email', 'badtoken@example.com')->value('email_verified'));
    }

    public function test_email_verification_rejects_an_expired_token(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Expired',
            'email' => 'expired@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('email', 'expired@example.com')->first();
        $token = $user->email_verification_token;
        // Push the expiry into the past.
        $user->forceFill(['email_verification_token_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/verify-email', [
            'email' => 'expired@example.com',
            'token' => $token,
        ])->assertStatus(401);

        $this->assertFalse((bool) $user->fresh()->email_verified);
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
