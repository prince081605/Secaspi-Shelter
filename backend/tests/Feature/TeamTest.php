<?php

namespace Tests\Feature;

use App\Models\RescueReport;
use App\Models\Team;
use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Team management: teams of staff and volunteers with a leader, rescue reports assigned to a
 * team (whose members are told), and tasks given to a whole team (a copy per member).
 */
class TeamTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->staff()->create();
        Sanctum::actingAs($this->staff);
    }

    private function person(string $name, string $type = 'volunteer'): Volunteer
    {
        $user = User::factory()->create(['full_name' => $name]);

        return Volunteer::create(['user_id' => $user->id, 'type' => $type]);
    }

    private function team(string $name = 'Rescue Team Alpha', array $members = []): Team
    {
        $id = $this->postJson('/api/admin/teams', ['name' => $name, 'purpose' => 'Street rescues in the north barangays'])
            ->assertCreated()->json('team.id');
        foreach ($members as $member) {
            $this->postJson("/api/admin/teams/{$id}/members", ['volunteer_id' => $member->id])->assertCreated();
        }

        return Team::findOrFail($id);
    }

    private function notifications(Volunteer $member, string $type): int
    {
        return DB::table('app_notifications')->where('user_id', $member->user_id)->where('type', $type)->count();
    }

    public function test_staff_create_teams_with_unique_names(): void
    {
        $this->team();
        $this->postJson('/api/admin/teams', ['name' => '  rescue team ALPHA '])
            ->assertStatus(422)->assertJsonPath('message', 'There is already a team called "rescue team ALPHA".');
        $this->postJson('/api/admin/teams', ['name' => ''])->assertStatus(422);
        $this->postJson('/api/admin/teams', ['name' => 'Feeding Crew'])->assertCreated();

        $this->getJson('/api/admin/teams')->assertOk()
            ->assertJsonCount(2, 'teams')
            ->assertJsonPath('teams.0.name', 'Feeding Crew')
            ->assertJsonPath('teams.1.purpose', 'Street rescues in the north barangays');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/teams')->assertForbidden();
        $this->postJson('/api/admin/teams', ['name' => 'Sneaky'])->assertForbidden();
    }

    public function test_members_and_the_leader(): void
    {
        $ana = $this->person('Ana Reyes');
        $ben = $this->person('Ben Cruz', 'staff');
        $outsider = $this->person('Carlo Diaz');
        $team = $this->team(members: [$ana, $ben]);

        $this->postJson("/api/admin/teams/{$team->id}/members", ['volunteer_id' => $ana->id])
            ->assertStatus(422)->assertJsonPath('message', 'They are already on this team.');
        $this->postJson("/api/admin/teams/{$team->id}/members", ['volunteer_id' => 999999])->assertStatus(422);

        $this->putJson("/api/admin/teams/{$team->id}", ['leader_id' => $outsider->id])
            ->assertStatus(422)->assertJsonPath('message', 'The team leader has to be a member of the team.');
        $this->putJson("/api/admin/teams/{$team->id}", ['leader_id' => $ben->id])->assertOk()
            ->assertJsonPath('team.leader', 'Ben Cruz')
            ->assertJsonPath('team.members.1.is_leader', true)
            ->assertJsonPath('team.members.1.type', 'staff');

        // Taking the leader off the team leaves it without one.
        $this->deleteJson("/api/admin/teams/{$team->id}/members/{$ben->id}")->assertOk()
            ->assertJsonPath('team.leader', null)
            ->assertJsonCount(1, 'team.members');
    }

    public function test_a_team_task_gives_every_member_their_own_copy(): void
    {
        $members = [$this->person('Ana Reyes'), $this->person('Ben Cruz'), $this->person('Carlo Diaz')];
        $team = $this->team(members: $members);

        $this->postJson("/api/admin/teams/{$team->id}/tasks", ['task_name' => 'Clean the kennels', 'assigned_date' => '2026-10-10'])
            ->assertCreated()
            ->assertJsonPath('team.tasks.0.task_name', 'Clean the kennels')
            ->assertJsonPath('team.tasks.0.total', 3)
            ->assertJsonPath('team.tasks.0.completed', 0);

        $copies = VolunteerTask::where('team_id', $team->id)->get();
        $this->assertCount(3, $copies);
        $this->assertCount(1, $copies->pluck('team_batch')->unique());
        $this->assertEqualsCanonicalizing(array_map(fn ($m) => $m->id, $members), $copies->pluck('volunteer_id')->all());
        foreach ($members as $member) {
            $this->assertSame(1, $this->notifications($member, 'volunteer_task'));
        }

        // Each copy goes through the usual verification (an admin signs it off); the team's progress follows.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/api/admin/volunteer-tasks/{$copies[0]->id}", ['status' => 'completed'])->assertOk();
        $this->getJson("/api/admin/teams/{$team->id}")->assertJsonPath('team.tasks.0.completed', 1);

        // The member sees it as a team task.
        Sanctum::actingAs($members[1]->user);
        $this->getJson('/api/volunteer/me')->assertJsonPath('volunteer.tasks.0.team', 'Rescue Team Alpha');
    }

    public function test_a_team_task_needs_members_and_an_active_team(): void
    {
        $empty = $this->team('Empty Team');
        $this->postJson("/api/admin/teams/{$empty->id}/tasks", ['task_name' => 'Walk the dogs'])
            ->assertStatus(422)->assertJsonPath('message', 'Add members to the team before giving it a task.');

        $team = $this->team(members: [$this->person('Ana Reyes')]);
        $this->putJson("/api/admin/teams/{$team->id}", ['is_active' => false])->assertOk()->assertJsonPath('team.is_active', false);
        $this->postJson("/api/admin/teams/{$team->id}/tasks", ['task_name' => 'Walk the dogs'])
            ->assertStatus(422)->assertJsonPath('message', 'This team is archived. Restore it before giving it tasks.');
        $this->assertDatabaseCount('volunteer_tasks', 0);
    }

    public function test_assigning_a_rescue_to_a_team_tells_its_members(): void
    {
        $ana = $this->person('Ana Reyes');
        $ben = $this->person('Ben Cruz');
        $team = $this->team(members: [$ana, $ben]);
        $this->putJson("/api/admin/teams/{$team->id}", ['leader_id' => $ana->id])->assertOk();
        $report = RescueReport::create(['location' => 'Purok 3, Brgy. San Isidro', 'urgency' => 'high', 'status' => 'pending']);

        $this->postJson("/api/rescue-reports/{$report->id}/status", ['team_id' => $team->id])->assertOk()
            ->assertJsonPath('report.status', 'assigned')
            ->assertJsonPath('report.team.name', 'Rescue Team Alpha');

        $this->assertSame(1, $this->notifications($ben, 'team_rescue'));
        $message = DB::table('app_notifications')->where('user_id', $ben->user_id)->where('type', 'team_rescue')->value('message');
        $this->assertStringContainsString('Purok 3, Brgy. San Isidro (urgency: high)', $message);
        $this->assertStringContainsString('team leader, Ana Reyes', $message);

        // Saving again with the same team doesn't tell them twice.
        $this->postJson("/api/rescue-reports/{$report->id}/status", ['team_id' => $team->id, 'status' => 'in_progress'])->assertOk();
        $this->assertSame(1, $this->notifications($ben, 'team_rescue'));

        $this->getJson("/api/rescue-reports?team_id={$team->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.team.name', 'Rescue Team Alpha');
        $this->getJson("/api/admin/teams/{$team->id}")->assertJsonPath('team.rescues.0.location', 'Purok 3, Brgy. San Isidro');

        // Their dashboard shows the team and the rescue it's on.
        Sanctum::actingAs($ben->user);
        $this->getJson('/api/volunteer/me')->assertOk()
            ->assertJsonPath('volunteer.teams.0.name', 'Rescue Team Alpha')
            ->assertJsonPath('volunteer.teams.0.leader', 'Ana Reyes')
            ->assertJsonPath('volunteer.teams.0.members.1.is_me', true)
            ->assertJsonPath('volunteer.teams.0.rescues.0.status', 'in_progress');
    }

    public function test_an_archived_team_cannot_take_new_rescues(): void
    {
        $team = $this->team();
        $this->putJson("/api/admin/teams/{$team->id}", ['is_active' => false])->assertOk();
        $report = RescueReport::create(['location' => 'Market', 'urgency' => 'low', 'status' => 'pending']);

        $this->postJson("/api/rescue-reports/{$report->id}/status", ['team_id' => $team->id])
            ->assertStatus(422)->assertJsonValidationErrors('team_id');
        $this->getJson('/api/admin/teams')->assertJsonPath('teams.0.is_active', false);
    }

    public function test_removing_someone_from_personnel_takes_them_off_their_teams(): void
    {
        $ana = $this->person('Ana Reyes');
        $team = $this->team(members: [$ana, $this->person('Ben Cruz')]);
        $this->putJson("/api/admin/teams/{$team->id}", ['leader_id' => $ana->id])->assertOk();

        $this->deleteJson("/api/admin/volunteers/{$ana->id}")->assertOk();

        $this->getJson("/api/admin/teams/{$team->id}")->assertJsonCount(1, 'team.members')->assertJsonPath('team.leader', null);
        $this->assertDatabaseMissing('team_members', ['volunteer_id' => $ana->id]);
    }
}
