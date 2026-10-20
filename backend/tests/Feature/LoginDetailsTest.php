<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * People sign in with their email or their username, so the admin account doesn't have to use
 * an address like admin@gmail.com. Nobody's email is changed from the admin side.
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

    public function test_an_admin_signs_in_with_a_username(): void
    {
        User::factory()->systemAdmin()->create(['email' => 'admin@gmail.com', 'username' => 'Admin']);

        $this->postJson('/api/login', ['login' => 'Admin', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'system_admin');
    }

    public function test_emails_are_not_changed_from_the_admin_side_or_the_profile(): void
    {
        $user = User::factory()->create(['email' => 'mine@example.com']);

        Sanctum::actingAs(User::factory()->systemAdmin()->create());
        $this->putJson("/api/admin/users/{$user->id}/login", ['email' => 'new@example.com', 'username' => 'Someone'])->assertNotFound();
        $this->putJson("/api/admin/users/{$user->id}", ['email' => 'new@example.com'])->assertOk();

        Sanctum::actingAs($user);
        $this->putJson('/api/profile', ['full_name' => $user->full_name, 'email' => 'other@example.com'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'mine@example.com']);
    }
}
