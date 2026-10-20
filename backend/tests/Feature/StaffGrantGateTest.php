<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Volunteer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Granting staff-level access (type=staff) promotes the account's role to `staff`. Handing out
 * roles is the System Admin's job, done from Users — so nobody mints staff through Personnel,
 * and a staff member or Desk Admin can't (audit §7 E-1).
 */
class StaffGrantGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_desk_cannot_create_staff_personnel(): void
    {
        foreach (['staff', 'deskAdmin'] as $state) {
            $target = User::factory()->create();
            Sanctum::actingAs(User::factory()->{$state}()->create());

            $this->postJson('/api/admin/volunteers', ['user_id' => $target->id, 'type' => 'staff'])
                ->assertStatus(403)
                ->assertJsonPath('message', 'Staff are added by a System Admin, from Users.');

            $this->assertSame('user', $target->fresh()->role, 'role must not be promoted');
        }

        $this->assertSame(0, Volunteer::count(), 'no volunteer row should be created');
    }

    public function test_the_system_admin_makes_staff_from_users(): void
    {
        $target = User::factory()->create();
        Sanctum::actingAs(User::factory()->systemAdmin()->create());

        $this->putJson("/api/admin/users/{$target->id}", ['role' => 'staff'])->assertOk();

        $this->assertSame('staff', $target->fresh()->role);
        $this->assertDatabaseHas('volunteers', ['user_id' => $target->id, 'type' => 'staff']);
    }

    public function test_staff_can_still_add_volunteers(): void
    {
        $target = User::factory()->create();
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->postJson('/api/admin/volunteers', ['user_id' => $target->id, 'type' => 'volunteer'])
            ->assertStatus(201);

        $this->assertSame('volunteer', $target->fresh()->role);
    }
}
