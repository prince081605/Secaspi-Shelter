<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proof of task completion: a volunteer sends a photo of the finished work, and the task waits
 * in 'submitted' until an admin signs it off.
 *
 * The point of the proof is that the volunteer does not mark their own work complete, so the
 * tests that matter most here are the ones about who may do what.
 */
class VolunteerTaskProofTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `create()` with an explicit mime rather than `image()`: the latter renders a real image and
     * so needs the GD extension, which is not installed on every dev machine here. The `image`
     * validation rule checks the mime type, so this satisfies it either way.
     */
    private function photo(string $name = 'done.jpg', int $kilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/jpeg');
    }

    /** @return array{0: User, 1: VolunteerTask} An approved volunteer with one assigned task. */
    private function volunteerWithTask(string $status = 'assigned'): array
    {
        $user = User::factory()->volunteer()->create();
        $volunteer = Volunteer::create(['user_id' => $user->id, 'type' => 'volunteer']);
        $task = $volunteer->tasks()->create(['task_name' => 'Walk the dogs', 'status' => $status]);

        return [$user, $task];
    }

    public function test_a_volunteer_can_send_proof_which_parks_the_task_for_review(): void
    {
        Storage::fake();
        [$user, $task] = $this->volunteerWithTask();
        Sanctum::actingAs($user);

        $this->postJson("/api/volunteer/tasks/{$task->id}/proof", [
            'proof_image' => $this->photo(),
            'proof_note' => 'Walked all six, back by 4pm.',
        ])->assertOk()->assertJsonPath('task.status', 'submitted');

        $task->refresh();
        // Submitted, NOT completed — signing off is the admin's job.
        $this->assertSame('submitted', $task->status);
        $this->assertSame('Walked all six, back by 4pm.', $task->proof_note);
        $this->assertNotNull($task->proof_submitted_at);
        Storage::assertExists($task->proof_path);
    }

    public function test_the_proof_photo_is_required_and_size_limited(): void
    {
        Storage::fake();
        [$user, $task] = $this->volunteerWithTask();
        Sanctum::actingAs($user);

        $this->postJson("/api/volunteer/tasks/{$task->id}/proof", ['proof_note' => 'done'])
            ->assertStatus(422)->assertJsonValidationErrors(['proof_image']);

        $this->postJson("/api/volunteer/tasks/{$task->id}/proof", [
            'proof_image' => $this->photo('huge.jpg', 6000),
        ])->assertStatus(422)->assertJsonValidationErrors(['proof_image']);

        $this->assertSame('assigned', $task->fresh()->status);
    }

    public function test_a_volunteer_cannot_send_proof_for_someone_elses_task(): void
    {
        Storage::fake();
        [, $task] = $this->volunteerWithTask();

        $intruderUser = User::factory()->volunteer()->create();
        Volunteer::create(['user_id' => $intruderUser->id, 'type' => 'volunteer']);
        Sanctum::actingAs($intruderUser);

        $this->postJson("/api/volunteer/tasks/{$task->id}/proof", ['proof_image' => $this->photo()])
            ->assertStatus(403);

        $this->assertNull($task->fresh()->proof_path);
    }

    public function test_a_task_that_is_not_approved_or_is_already_done_takes_no_proof(): void
    {
        Storage::fake();

        [$requestedUser, $requested] = $this->volunteerWithTask('requested');
        Sanctum::actingAs($requestedUser);
        $this->postJson("/api/volunteer/tasks/{$requested->id}/proof", ['proof_image' => $this->photo()])
            ->assertStatus(409);

        [$doneUser, $done] = $this->volunteerWithTask('completed');
        Sanctum::actingAs($doneUser);
        $this->postJson("/api/volunteer/tasks/{$done->id}/proof", ['proof_image' => $this->photo()])
            ->assertStatus(409);
    }

    public function test_resending_proof_replaces_the_photo_rather_than_orphaning_it(): void
    {
        Storage::fake();
        [$user, $task] = $this->volunteerWithTask();
        Sanctum::actingAs($user);

        $this->postJson("/api/volunteer/tasks/{$task->id}/proof", ['proof_image' => $this->photo('first.jpg')])->assertOk();
        $first = $task->fresh()->proof_path;

        $this->postJson("/api/volunteer/tasks/{$task->id}/proof", ['proof_image' => $this->photo('second.jpg')])->assertOk();
        $second = $task->fresh()->proof_path;

        $this->assertNotSame($first, $second);
        Storage::assertMissing($first);
        Storage::assertExists($second);
    }

    public function test_staff_see_the_proof_and_can_sign_the_task_off(): void
    {
        Storage::fake();
        [$user, $task] = $this->volunteerWithTask();
        Sanctum::actingAs($user);
        $this->postJson("/api/volunteer/tasks/{$task->id}/proof", ['proof_image' => $this->photo()])->assertOk();

        Sanctum::actingAs(User::factory()->staff()->create());

        $row = $this->getJson('/api/admin/volunteers')->assertOk()->json('data.0.tasks.0');
        $this->assertSame('submitted', $row['status']);
        $this->assertNotNull($row['proof_url']);

        $this->putJson("/api/admin/volunteer-tasks/{$task->id}", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('task.status', 'completed');
    }

    public function test_a_submitted_task_counts_towards_the_volunteers_badge(): void
    {
        Storage::fake();
        [$user, $task] = $this->volunteerWithTask();
        Sanctum::actingAs($user);
        $this->postJson("/api/volunteer/tasks/{$task->id}/proof", ['proof_image' => $this->photo()])->assertOk();

        Sanctum::actingAs(User::factory()->staff()->create());
        // Proof waiting for sign-off is work sitting on staff, so it belongs in the same badge
        // as volunteer applications waiting for a decision.
        $this->getJson('/api/admin/dashboard/pending-counts')->assertOk()->assertJsonPath('volunteer', 1);
    }
}
