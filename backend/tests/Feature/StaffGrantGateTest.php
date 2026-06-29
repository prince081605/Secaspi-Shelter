<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Volunteer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Granting staff-level access (type=staff) promotes the account's role to `staff`, so it must be
 * an admin-only action — a staff member must not be able to mint more staff (audit §7 E-1).
 */
class StaffGrantGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_create_staff_personnel(): void
    {
        $target = User::factory()->create();
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->postJson('/api/admin/volunteers', ['user_id' => $target->id, 'type' => 'staff'])
            ->assertStatus(403);

        $this->assertSame('user', $target->fresh()->role, 'role must not be promoted');
        $this->assertSame(0, Volunteer::count(), 'no volunteer row should be created');
    }

    public function test_admin_can_create_staff_personnel(): void
    {
        $target = User::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/volunteers', ['user_id' => $target->id, 'type' => 'staff'])
            ->assertStatus(201);

        $this->assertSame('staff', $target->fresh()->role);
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
