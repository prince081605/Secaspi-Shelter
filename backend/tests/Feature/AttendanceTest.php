<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Attendance: volunteers and staff clock themselves in and out, their hours rendered follow the
 * log, staff can see it, and only admins can change it.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Volunteer} */
    private function person(string $type = 'volunteer', float $existingHours = 0): array
    {
        $user = $type === 'staff' ? User::factory()->staff()->create() : User::factory()->volunteer()->create();
        $volunteer = Volunteer::create(['user_id' => $user->id, 'type' => $type]);
        if ($existingHours) {
            $volunteer->forceFill(['hours_rendered' => $existingHours, 'hours_adjustment' => $existingHours])->save();
        }

        return [$user, $volunteer->fresh()];
    }

    public function test_clocking_in_and_out_records_a_shift_and_adds_its_hours(): void
    {
        [$user, $volunteer] = $this->person('volunteer', 10);
        Sanctum::actingAs($user);

        $this->travelTo(now()->setTime(1, 0));
        $this->postJson('/api/volunteer/attendance/clock-in')->assertCreated()
            ->assertJsonPath('attendance.on_duty', true);

        $clockedInAt = now()->toDateTimeString();
        $this->travel(90)->minutes();
        $this->postJson('/api/volunteer/attendance/clock-out')->assertOk()
            ->assertJsonPath('attendance.on_duty', false)
            ->assertJsonPath('attendance.recent.0.minutes', 90);

        // Closing the shift must leave its start alone (a MySQL "ON UPDATE CURRENT_TIMESTAMP"
        // column once rewrote time_in to the clock-out time).
        $this->assertSame($clockedInAt, VolunteerAttendance::first()->time_in->toDateTimeString());

        // The hand-entered 10 hours are kept; the 1.5-hour shift is added on top.
        $this->assertEquals(11.5, $volunteer->fresh()->hours_rendered);
    }

    public function test_you_cannot_clock_in_twice_or_clock_out_when_not_on_duty(): void
    {
        [$user] = $this->person();
        Sanctum::actingAs($user);

        $this->postJson('/api/volunteer/attendance/clock-out')->assertStatus(409);
        $this->postJson('/api/volunteer/attendance/clock-in')->assertCreated();
        $this->postJson('/api/volunteer/attendance/clock-in')->assertStatus(409);

        $this->assertSame(1, VolunteerAttendance::count());
    }

    public function test_a_forgotten_clock_out_is_capped(): void
    {
        [$user, $volunteer] = $this->person();
        Sanctum::actingAs($user);

        $this->postJson('/api/volunteer/attendance/clock-in')->assertCreated();
        $this->travel(20)->hours();
        $this->postJson('/api/volunteer/attendance/clock-out')->assertOk();

        $this->assertSame(VolunteerAttendance::MAX_SELF_MINUTES, VolunteerAttendance::first()->minutes);
        $this->assertEquals(12, $volunteer->fresh()->hours_rendered);
    }

    public function test_staff_can_clock_in_too_but_regular_users_cannot(): void
    {
        [$staffUser] = $this->person('staff');
        Sanctum::actingAs($staffUser);
        $this->postJson('/api/volunteer/attendance/clock-in')->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/volunteer/attendance/clock-in')->assertStatus(403);
        $this->getJson('/api/volunteer/attendance')->assertOk()->assertJsonPath('attendance', null);
    }

    public function test_staff_see_the_log_and_who_is_on_duty_but_cannot_change_it(): void
    {
        [$user, $volunteer] = $this->person();
        Sanctum::actingAs($user);
        $this->postJson('/api/volunteer/attendance/clock-in')->assertCreated();
        $record = VolunteerAttendance::first();

        Sanctum::actingAs(User::factory()->staff()->create());
        $this->getJson('/api/admin/attendance')->assertOk()
            ->assertJsonPath('on_duty.0.volunteer.id', $volunteer->id)
            ->assertJsonPath('data.0.id', $record->id);

        $this->putJson("/api/admin/attendance/{$record->id}", ['time_in' => now()->subHour()->toIso8601String()])->assertForbidden();
        $this->deleteJson("/api/admin/attendance/{$record->id}")->assertForbidden();
        $this->postJson("/api/admin/volunteers/{$volunteer->id}/attendance", ['time_in' => now()->subHour()->toIso8601String()])->assertForbidden();
    }

    public function test_an_admin_can_add_correct_and_delete_records_and_hours_follow(): void
    {
        [, $volunteer] = $this->person('volunteer', 5);
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $in = now()->subHours(5);
        $this->postJson("/api/admin/volunteers/{$volunteer->id}/attendance", [
            'time_in' => $in->toIso8601String(),
            'time_out' => $in->copy()->addHours(3)->toIso8601String(),
            'notes' => 'Forgot to clock in',
        ])->assertCreated()->assertJsonPath('attendance.recorded_by', $admin->full_name);
        $this->assertEquals(8, $volunteer->fresh()->hours_rendered);

        $record = VolunteerAttendance::first();
        $this->putJson("/api/admin/attendance/{$record->id}", [
            'time_in' => $in->toIso8601String(),
            'time_out' => $in->copy()->addHours(2)->toIso8601String(),
        ])->assertOk()->assertJsonPath('attendance.minutes', 120);
        $this->assertEquals(7, $volunteer->fresh()->hours_rendered);

        $this->deleteJson("/api/admin/attendance/{$record->id}")->assertOk();
        $this->assertEquals(5, $volunteer->fresh()->hours_rendered);
    }

    public function test_a_record_must_end_after_it_starts_and_last_at_most_a_day(): void
    {
        [, $volunteer] = $this->person();
        Sanctum::actingAs(User::factory()->admin()->create());
        $in = now()->subDays(3);

        $this->postJson("/api/admin/volunteers/{$volunteer->id}/attendance", [
            'time_in' => $in->toIso8601String(), 'time_out' => $in->copy()->subHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['time_out']);

        $this->postJson("/api/admin/volunteers/{$volunteer->id}/attendance", [
            'time_in' => $in->toIso8601String(), 'time_out' => $in->copy()->addHours(30)->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['time_out']);
    }

    public function test_setting_total_hours_is_admin_only_and_keeps_attendance_hours(): void
    {
        [, $volunteer] = $this->person();
        DB::table('volunteer_attendances')->insert([
            'volunteer_id' => $volunteer->id, 'time_in' => now()->subHours(3), 'time_out' => now()->subHour(),
            'minutes' => 120, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $volunteer->recalculateHours();

        Sanctum::actingAs(User::factory()->staff()->create());
        $this->putJson("/api/admin/volunteers/{$volunteer->id}", ['hours_rendered' => 50])->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/api/admin/volunteers/{$volunteer->id}", ['hours_rendered' => 50])->assertOk()
            ->assertJsonPath('volunteer.hours_rendered', 50);

        $fresh = $volunteer->fresh();
        $this->assertEquals(48, $fresh->hours_adjustment);
        $this->assertEquals(50, $fresh->hours_rendered);
    }

    public function test_the_attendance_report_counts_days_shifts_and_hours_in_the_range(): void
    {
        [, $volunteer] = $this->person();
        $day = now('Asia/Manila')->subDays(2)->setTime(9, 0);
        foreach ([[0, 120], [5, 60]] as [$hourOffset, $minutes]) {
            $in = $day->copy()->addHours($hourOffset)->utc();
            DB::table('volunteer_attendances')->insert([
                'volunteer_id' => $volunteer->id, 'time_in' => $in, 'time_out' => $in->copy()->addMinutes($minutes),
                'minutes' => $minutes, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs(User::factory()->staff()->create());
        $row = $this->getJson('/api/admin/reports/attendance?from='.$day->toDateString().'&to='.$day->toDateString())
            ->assertOk()->json('rows.0');

        $this->assertSame(1, $row['days']);
        $this->assertSame(2, $row['shifts']);
        $this->assertEquals(3, $row['hours']);

        $this->getJson('/api/admin/reports/attendance?from='.now('Asia/Manila')->toDateString())
            ->assertOk()->assertJsonCount(0, 'rows');
    }
}
