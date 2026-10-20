<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\BlockedReporter;
use App\Models\Reminder;
use App\Models\RescueReport;
use App\Models\User;
use App\Services\ReportScreening;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin split (docs/ADMIN_ROLES.md): no Super Admin, three admin types each limited to its
 * own area, and staff/volunteers/users unchanged.
 */
class AdminRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_role_holds_exactly_the_documented_abilities(): void
    {
        // The permission table in docs/ADMIN_ROLES.md, as abilities. Changing who can do what
        // means changing this and the doc together.
        $this->assertSame([
            'user' => [],
            'volunteer' => [],
            'staff' => ['desk.operate', 'reports.view'],
            'desk_admin' => ['desk.operate', 'desk.supervise'],
            'data_admin' => ['reports.view', 'reports.financial', 'master_data.manage'],
            'system_admin' => ['users.manage', 'settings.manage', 'security.manage'],
        ], collect(array_keys(Permissions::LABELS))->mapWithKeys(fn ($role) => [$role => Permissions::forRole($role)])->all());
    }

    public function test_no_role_has_full_access_and_no_two_admin_types_share_an_ability(): void
    {
        foreach (array_keys(Permissions::LABELS) as $role) {
            $this->assertLessThan(count(Permissions::MAP), count(Permissions::forRole($role)), "{$role} holds every ability");
        }

        foreach (Permissions::MAP as $ability => $roles) {
            $this->assertLessThanOrEqual(1, count(array_intersect($roles, Permissions::ADMIN_ROLES)), "{$ability} is held by more than one admin type");
        }

        $this->assertArrayNotHasKey('admin', Permissions::LABELS);
    }

    public function test_the_old_admin_role_can_no_longer_be_given_or_used(): void
    {
        $target = User::factory()->create();
        Sanctum::actingAs(User::factory()->systemAdmin()->create());
        $this->putJson("/api/admin/users/{$target->id}", ['role' => 'admin'])->assertStatus(422);
        $this->putJson("/api/admin/users/{$target->id}", ['role' => 'super_admin'])->assertStatus(422);
        $this->assertSame('user', $target->fresh()->role);

        // An account still holding the old value (say, restored from a backup) gets nothing.
        $legacy = User::factory()->create(['role' => 'admin']);
        $this->assertSame([], $legacy->permissions());
        Sanctum::actingAs($legacy);
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/animals')->assertForbidden();
    }

    public function test_the_migration_makes_existing_admins_system_admins(): void
    {
        $migration = require database_path('migrations/2026_10_20_000000_split_admin_role.php');
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->staff()->create();

        $migration->up();
        $this->assertSame('system_admin', $admin->fresh()->role);
        $this->assertSame('staff', $staff->fresh()->role);

        $migration->down();
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('staff', $staff->fresh()->role);
    }

    public function test_the_system_admin_hands_out_every_role_but_not_to_themselves(): void
    {
        $me = User::factory()->systemAdmin()->create();
        $target = User::factory()->staff()->create();
        Sanctum::actingAs($me);

        foreach (['desk_admin', 'data_admin', 'system_admin', 'staff'] as $role) {
            $this->putJson("/api/admin/users/{$target->id}", ['role' => $role])
                ->assertOk()
                ->assertJsonPath('user.role', $role)
                ->assertJsonPath('user.role_label', Permissions::label($role));
        }

        // Making someone an admin type takes them off the personnel roster, as admin always did.
        $this->putJson("/api/admin/users/{$target->id}", ['role' => 'desk_admin'])->assertOk();
        $this->assertDatabaseMissing('volunteers', ['user_id' => $target->id]);

        // No one changes their own role or suspends themselves — which also means the last
        // System Admin can't be removed (only another active System Admin could do it).
        $this->putJson("/api/admin/users/{$me->id}", ['role' => 'desk_admin'])->assertStatus(422);
        $this->putJson("/api/admin/users/{$me->id}", ['status' => 'suspended'])->assertStatus(422);
        $this->putJson("/api/admin/users/{$me->id}", ['role' => 'system_admin'])->assertOk();
    }

    public function test_the_desk_picks_new_volunteers_without_the_users_list(): void
    {
        $maria = User::factory()->create(['full_name' => 'Maria Santos']);
        User::factory()->volunteer()->create(['full_name' => 'Maria Volunteer']);
        User::factory()->dataAdmin()->create(['full_name' => 'Maria Data']);

        foreach (['staff', 'deskAdmin'] as $state) {
            Sanctum::actingAs(User::factory()->{$state}()->create());
            $this->getJson('/api/admin/users')->assertForbidden();
            $this->getJson('/api/admin/volunteers/candidates?q=maria')->assertOk()
                ->assertJsonCount(1, 'users')
                ->assertJsonPath('users.0.id', $maria->id);
        }
    }

    public function test_the_signed_in_user_carries_their_abilities_and_role_label(): void
    {
        Sanctum::actingAs(User::factory()->deskAdmin()->create());

        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('user.role', 'desk_admin')
            ->assertJsonPath('user.role_label', 'Desk Admin')
            ->assertJsonPath('user.permissions', ['desk.operate', 'desk.supervise']);

        $this->postJson('/api/login', ['login' => User::factory()->dataAdmin()->create()->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role_label', 'Data Admin')
            ->assertJsonPath('user.permissions', ['reports.view', 'reports.financial', 'master_data.manage']);
    }

    public function test_the_system_admin_summary_counts_accounts(): void
    {
        User::factory()->count(2)->create();
        User::factory()->staff()->suspended()->create();
        Sanctum::actingAs(User::factory()->systemAdmin()->create());

        $res = $this->getJson('/api/admin/users/summary')->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('suspended', 1);
        $counts = collect($res->json('roles'))->pluck('count', 'role');
        $this->assertSame(2, $counts['user']);
        $this->assertSame(1, $counts['staff']);
        $this->assertSame(1, $counts['system_admin']);
        $this->assertSame(0, $counts['desk_admin']);
    }

    public function test_the_security_page_lists_fake_reporters_and_blocks_them(): void
    {
        $fake = fn (string $contact, string $when) => RescueReport::create([
            'location' => 'Market', 'urgency' => 'high', 'status' => 'invalid', 'invalid_reason' => 'fake',
            'contact_number' => $contact, 'contact_hash' => ReportScreening::contactHash($contact), 'created_at' => $when,
        ]);
        $fake('+639171234567', '2026-10-01 08:00:00');
        $latest = $fake('09171234567', '2026-10-02 08:00:00');   // same number, other format
        $fake('09181112222', '2026-10-03 08:00:00');
        RescueReport::create(['location' => 'Plaza', 'urgency' => 'low', 'status' => 'invalid', 'invalid_reason' => 'duplicate',
            'contact_number' => '09190000000', 'contact_hash' => ReportScreening::contactHash('09190000000')]);

        Sanctum::actingAs(User::factory()->deskAdmin()->create());
        $this->getJson('/api/admin/security/flagged-reporters')->assertForbidden();
        $this->postJson("/api/rescue-reports/{$latest->id}/block")->assertForbidden();

        Sanctum::actingAs(User::factory()->systemAdmin()->create());
        $reporters = $this->getJson('/api/admin/security/flagged-reporters')->assertOk()->json('reporters');
        $this->assertCount(2, $reporters, 'duplicates are not fake reports');
        $this->assertEquals(['report_id' => $latest->id, 'fake_reports' => 2, 'blocked' => false, 'label' => '0917 •••• 567'],
            collect($reporters[0])->only(['report_id', 'fake_reports', 'blocked', 'label'])->all());

        $this->postJson("/api/rescue-reports/{$latest->id}/block", ['reason' => 'Two fake reports'])->assertCreated();
        $this->assertTrue($this->getJson('/api/admin/security/flagged-reporters')->json('reporters.0.blocked'));
        $this->getJson('/api/admin/blocked-reporters')->assertOk()->assertJsonCount(1, 'blocked');
        $this->deleteJson('/api/admin/blocked-reporters/'.BlockedReporter::first()->id)->assertOk();
    }

    public function test_desk_notifications_reach_the_desk_only(): void
    {
        $staff = User::factory()->staff()->create();
        $desk = User::factory()->deskAdmin()->create();
        $suspendedDesk = User::factory()->deskAdmin()->suspended()->create();
        $data = User::factory()->dataAdmin()->create();
        $system = User::factory()->systemAdmin()->create();
        $count = fn (User $u, string $type) => DB::table('app_notifications')->where('user_id', $u->id)->where('type', $type)->count();

        // A member's message goes to staff and Desk Admins.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/conversations', ['subject' => 'Adoption question', 'body' => 'Is Brownie still available?'])->assertCreated();
        $this->assertSame([1, 1, 0, 0, 0], array_map(fn ($u) => $count($u, 'new_message'), [$staff, $desk, $suspendedDesk, $data, $system]));

        // Due health reminders go to Desk Admins (staff see them on Health Reminders, as before).
        $animal = Animal::create(['name' => 'Brownie', 'species' => 'dog', 'status' => 'available']);
        Reminder::create(['animal_id' => $animal->id, 'title' => 'Rabies booster', 'reminder_date' => now()->subDay()->toDateString(), 'status' => 'pending']);
        $this->artisan('reminders:dispatch')->assertSuccessful();
        $this->assertSame([0, 1, 0, 0, 0], array_map(fn ($u) => $count($u, 'reminder_due'), [$staff, $desk, $suspendedDesk, $data, $system]));
    }
}
