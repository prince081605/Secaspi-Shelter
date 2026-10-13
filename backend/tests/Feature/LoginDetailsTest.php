<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * People sign in with their email or their username, and only an admin changes an account's
 * email or username.
 */
class LoginDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_with_email_or_username_in_any_case(): void
    {
        User::factory()->create(['email' => 'juan@example.com', 'username' => 'Juan D.']);

        $this->postJson('/api/login', ['login' => 'Juan@Example.com', 'password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['login' => 'juan d.', 'password' => 'password'])->assertOk();
        // Older clients still send the field as `email`.
        $this->postJson('/api/login', ['email' => 'juan@example.com', 'password' => 'password'])->assertOk();

        $this->postJson('/api/login', ['login' => 'Juan D.', 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/login', ['password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter your email or username.');
    }

    public function test_an_admin_changes_email_and_username(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'username' => 'Maria S.', 'email_verified' => true]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson("/api/admin/users/{$user->id}/login", ['email' => 'new@example.com', 'username' => 'maria.santos'])
            ->assertOk()
            ->assertJsonPath('email_changed', true)
            ->assertJsonPath('user.email', 'new@example.com')
            ->assertJsonPath('user.username', 'maria.santos')
            // The new address has to be confirmed again.
            ->assertJsonPath('user.email_verified', false);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['login' => 'maria.santos', 'password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['login' => 'old@example.com', 'password' => 'password'])->assertStatus(401);
    }

    public function test_username_only_change_keeps_the_email_verified(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'username' => 'Ana R.', 'email_verified' => true]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson("/api/admin/users/{$user->id}/login", ['email' => 'ANA@example.com', 'username' => 'Ana Reyes'])
            ->assertOk()
            ->assertJsonPath('email_changed', false)
            ->assertJsonPath('user.email_verified', true);
    }

    public function test_taken_or_invalid_details_are_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com', 'username' => 'Taken']);
        $user = User::factory()->create(['email' => 'me@example.com', 'username' => 'Me Myself']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson("/api/admin/users/{$user->id}/login", ['email' => 'TAKEN@example.com', 'username' => 'Me Myself'])
            ->assertStatus(422)->assertJsonPath('message', 'Another account already uses this email.');
        $this->putJson("/api/admin/users/{$user->id}/login", ['email' => 'me@example.com', 'username' => 'taken'])
            ->assertStatus(422)->assertJsonPath('message', 'Another account already uses this username.');
        $this->putJson("/api/admin/users/{$user->id}/login", ['email' => 'me@example.com', 'username' => 'me@home'])
            ->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_only_admins_change_login_details(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs(User::factory()->staff()->create());
        $this->putJson("/api/admin/users/{$user->id}/login", ['email' => 'x@example.com', 'username' => 'Someone'])->assertForbidden();

        // Nobody changes their own email from their profile.
        Sanctum::actingAs($user);
        $this->putJson('/api/profile', ['full_name' => $user->full_name, 'email' => 'mine@example.com'])->assertOk();
        $this->assertDatabaseMissing('users', ['email' => 'mine@example.com']);
    }
}
