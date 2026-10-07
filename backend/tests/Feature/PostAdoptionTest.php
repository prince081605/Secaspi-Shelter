<?php

namespace Tests\Feature;

use App\Models\AdoptionApplication;
use App\Models\AdoptionFollowUp;
use App\Models\AdoptionUpdate;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Post-adoption care: completing an adoption schedules check-ins; adopters send updates (which
 * answer a due check-in) and can offer them as Happy Tails stories; staff record check-ins,
 * approve stories, and decide return requests — which, if accepted, put the animal back in care.
 */
class PostAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private User $adopter;

    private User $staff;

    private int $animalId;

    private AdoptionApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-07 10:00:00');

        $this->adopter = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        $this->staff = User::factory()->staff()->create();
        $this->animalId = DB::table('animals')->insertGetId([
            'name' => 'Brownie', 'species' => 'dog', 'status' => 'adopted', 'created_at' => now(),
        ]);
        $this->application = AdoptionApplication::create([
            'user_id' => $this->adopter->id, 'animal_id' => $this->animalId, 'reference_no' => 'ADP-TEST-1',
            'status' => 'approved', 'full_name' => 'Juan Dela Cruz', 'contact_number' => '09171234567',
        ]);
    }

    private function complete(): void
    {
        Sanctum::actingAs($this->staff);
        $this->putJson("/api/admin/adoption-applications/{$this->application->id}", ['status' => 'completed'])->assertOk();
    }

    private function sendUpdate(array $fields = []): TestResponse
    {
        Sanctum::actingAs($this->adopter);

        return $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/my-adoptions/{$this->application->id}/updates", [
                'wellbeing' => 'doing_well', 'message' => 'Brownie loves the yard and sleeps on the couch.', ...$fields,
            ]);
    }

    private function notified(User $user, string $type): int
    {
        return DB::table('app_notifications')->where('user_id', $user->id)->where('type', $type)->count();
    }

    public function test_completing_an_adoption_schedules_check_ins_that_also_show_as_reminders(): void
    {
        $this->complete();

        $this->application->refresh();
        $this->assertSame('2026-10-07', $this->application->completed_at->toDateString());
        $this->assertSame(
            ['1 week' => '2026-10-14', '1 month' => '2026-11-06', '3 months' => '2027-01-05', '6 months' => '2027-04-05'],
            $this->application->followUps->mapWithKeys(fn ($f) => [$f->label => $f->due_date->toDateString()])->all(),
        );
        $this->assertSame(4, Reminder::where('remindable_type', AdoptionFollowUp::class)->count());
        $this->assertTrue(Reminder::where('title', 'Post-adoption check-in (1 week) — Brownie, adopted by Juan Dela Cruz')
            ->whereDate('reminder_date', '2026-10-14')->exists());
        $this->assertStringContainsString('welcome home', DB::table('app_notifications')->where('user_id', $this->adopter->id)->value('message'));

        // Moved back out of Completed and in again: the same four check-ins, not eight.
        $this->putJson("/api/admin/adoption-applications/{$this->application->id}", ['status' => 'approved'])->assertOk();
        $this->assertSame(4, AdoptionFollowUp::where('status', 'cancelled')->count());
        $this->assertSame(0, Reminder::count());
        $this->putJson("/api/admin/adoption-applications/{$this->application->id}", ['status' => 'completed'])->assertOk();
        $this->assertSame(4, AdoptionFollowUp::where('status', 'pending')->count());
        $this->assertSame(4, AdoptionFollowUp::count());
        $this->assertSame(4, Reminder::count());
    }

    public function test_staff_record_a_due_check_in(): void
    {
        $this->complete();
        $this->travelTo('2026-10-15 09:00:00');

        $due = $this->getJson('/api/admin/post-adoption/check-ins')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', '1 week')
            ->assertJsonPath('data.0.overdue', true)
            ->assertJsonPath('data.0.adoption.adopter', 'Juan Dela Cruz')
            ->json('data.0');
        $this->getJson('/api/admin/post-adoption/check-ins?view=upcoming')->assertJsonCount(3, 'data');

        $this->putJson("/api/admin/post-adoption/check-ins/{$due['id']}", ['contact_method' => 'call'])
            ->assertStatus(422)->assertJsonPath('message', 'Choose how the animal is doing.');
        $this->putJson("/api/admin/post-adoption/check-ins/{$due['id']}", [
            'contact_method' => 'call', 'wellbeing' => 'doing_well', 'notes' => 'Eating well, house-trained.',
        ])->assertOk()->assertJsonPath('check_in.status', 'done')->assertJsonPath('check_in.recorded_by', $this->staff->full_name);

        $this->getJson('/api/admin/post-adoption/check-ins')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/post-adoption/check-ins?view=done')->assertJsonPath('data.0.notes', 'Eating well, house-trained.');
        $this->assertSame('completed', Reminder::where('remindable_id', $due['id'])->value('status'));
        $this->putJson("/api/admin/post-adoption/check-ins/{$due['id']}", ['contact_method' => 'call', 'wellbeing' => 'doing_well'])
            ->assertStatus(422);

        Sanctum::actingAs($this->adopter);
        $this->getJson('/api/admin/post-adoption/check-ins')->assertForbidden();
    }

    public function test_the_adopter_sends_an_update_that_answers_the_check_in_due(): void
    {
        Storage::fake();
        $this->complete();
        $this->travelTo('2026-10-12 18:00:00'); // two days before the 1-week check-in

        Sanctum::actingAs($this->adopter);
        $this->getJson('/api/my-adoptions')->assertOk()
            ->assertJsonCount(1, 'adoptions')
            ->assertJsonPath('adoptions.0.animal.name', 'Brownie')
            ->assertJsonPath('adoptions.0.next_check_in.label', '1 week');

        $this->sendUpdate(['photos' => [
            UploadedFile::fake()->create('yard.jpg', 200, 'image/jpeg'),
            UploadedFile::fake()->create('couch.jpg', 200, 'image/jpeg'),
        ]])->assertCreated()->assertJsonCount(2, 'update.photos');

        $update = AdoptionUpdate::firstOrFail();
        Storage::assertExists($update->photo_paths);
        $answered = AdoptionFollowUp::where('label', '1 week')->firstOrFail();
        $this->assertSame('done', $answered->status);
        $this->assertSame('adopter_update', $answered->contact_method);
        $this->assertSame('doing_well', $answered->wellbeing);
        $this->assertSame($answered->id, (int) $update->follow_up_id);
        $this->assertSame(0, $this->notified($this->staff, 'post_adoption_alert'));

        // "Need help" alerts staff.
        $this->sendUpdate(['wellbeing' => 'need_help', 'message' => 'He growls at our baby.'])->assertCreated();
        $this->assertSame(1, $this->notified($this->staff, 'post_adoption_alert'));

        // Required fields; no more than four photos.
        $this->sendUpdate(['message' => ''])->assertStatus(422);
        $this->sendUpdate(['photos' => array_fill(0, 5, UploadedFile::fake()->create('p.jpg', 10, 'image/jpeg'))])
            ->assertStatus(422)->assertJsonPath('message', 'You can add up to 4 photos to one update.');
    }

    public function test_only_the_adopter_can_post_and_only_once_the_adoption_is_completed(): void
    {
        $this->sendUpdate()->assertStatus(422); // still "approved"

        $this->complete();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/my-adoptions')->assertOk()->assertJsonCount(0, 'adoptions');
        $this->postJson("/api/my-adoptions/{$this->application->id}/updates", ['wellbeing' => 'doing_well', 'message' => 'Hi'])
            ->assertNotFound();
        $this->postJson("/api/my-adoptions/{$this->application->id}/return", ['reason' => 'Not mine'])->assertNotFound();
        $this->assertDatabaseCount('adoption_updates', 0);
    }

    public function test_a_happy_tails_story_needs_the_adopters_consent_and_staff_approval(): void
    {
        $this->complete();
        $private = $this->sendUpdate()->json('update.id');
        $shared = $this->sendUpdate(['share_publicly' => true])->assertJsonPath('update.story_status', 'pending')->json('update.id');
        $this->getJson('/api/home/happy-tails')->assertOk()->assertJsonCount(0, 'stories');

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/admin/post-adoption/updates?filter=stories')->assertJsonCount(1, 'data');
        $this->putJson("/api/admin/post-adoption/updates/{$private}/story", ['decision' => 'approved'])
            ->assertStatus(422)->assertJsonPath('message', 'The adopter has not offered this update as a public story.');
        $this->putJson("/api/admin/post-adoption/updates/{$shared}/story", ['decision' => 'approved'])->assertOk()
            ->assertJsonPath('update.story_status', 'approved');

        $story = $this->getJson('/api/home/happy-tails')->assertJsonCount(1, 'stories')->json('stories.0');
        $this->assertSame('Brownie', $story['animal_name']);
        $this->assertSame('Juan', $story['adopter']);
        $this->assertStringNotContainsString($this->adopter->email, json_encode($story));
        $this->assertSame(1, $this->notified($this->adopter, 'happy_tails'));

        // The adopter can take it back down at any time.
        Sanctum::actingAs($this->adopter);
        $this->postJson("/api/my-adoptions/{$this->application->id}/updates/{$shared}/stop-sharing")->assertOk();
        $this->getJson('/api/home/happy-tails')->assertJsonCount(0, 'stories');
    }

    public function test_an_accepted_return_puts_the_animal_back_in_care(): void
    {
        $this->complete();
        // A published Happy Tails story about this adoption, to check it comes down with the return.
        AdoptionUpdate::create([
            'adoption_application_id' => $this->application->id, 'animal_id' => $this->animalId, 'user_id' => $this->adopter->id,
            'wellbeing' => 'doing_well', 'message' => 'All good.', 'share_publicly' => true, 'story_status' => 'approved',
        ]);
        $this->getJson('/api/home/happy-tails')->assertJsonCount(1, 'stories');
        Sanctum::actingAs($this->adopter);
        $this->postJson("/api/my-adoptions/{$this->application->id}/return", ['reason' => ''])->assertStatus(422);
        $returnId = $this->postJson("/api/my-adoptions/{$this->application->id}/return", ['reason' => 'We are moving abroad.'])
            ->assertCreated()->json('return.id');
        $this->postJson("/api/my-adoptions/{$this->application->id}/return", ['reason' => 'Again'])
            ->assertStatus(422)->assertJsonPath('message', 'You already have a return request waiting for the shelter to review.');
        $this->assertSame(1, $this->notified($this->staff, 'post_adoption_alert'));

        Sanctum::actingAs($this->staff);
        $this->putJson("/api/admin/post-adoption/returns/{$returnId}", ['decision' => 'accepted'])
            ->assertStatus(422)->assertJsonPath('message', 'Choose where the animal goes when it comes back.');
        $this->putJson("/api/admin/post-adoption/returns/{$returnId}", [
            'decision' => 'accepted', 'animal_status' => 'medical', 'staff_notes' => 'Vet check first.',
        ])->assertOk()->assertJsonPath('return.status', 'accepted');

        $this->assertSame('returned', $this->application->fresh()->status);
        $this->assertDatabaseHas('animals', ['id' => $this->animalId, 'status' => 'medical']);
        $this->assertSame(4, AdoptionFollowUp::where('status', 'cancelled')->count());
        $this->assertSame(0, Reminder::count());
        $this->assertSame(1, $this->notified($this->adopter, 'adoption_return'));
        $this->getJson('/api/home/happy-tails')->assertJsonCount(0, 'stories');
        $this->putJson("/api/admin/post-adoption/returns/{$returnId}", ['decision' => 'declined'])->assertStatus(422);

        Sanctum::actingAs($this->adopter);
        $this->getJson('/api/my-adoptions')->assertJsonPath('adoptions.0.status', 'returned')
            ->assertJsonPath('adoptions.0.return_request.status', 'accepted')
            ->assertJsonPath('adoptions.0.next_check_in', null);
        $this->sendUpdate()->assertStatus(422);
    }

    public function test_a_declined_return_leaves_the_adoption_as_it_was(): void
    {
        $this->complete();
        Sanctum::actingAs($this->adopter);
        $returnId = $this->postJson("/api/my-adoptions/{$this->application->id}/return", ['reason' => 'He barks a lot.'])->json('return.id');

        Sanctum::actingAs($this->staff);
        $this->putJson("/api/admin/post-adoption/returns/{$returnId}", ['decision' => 'declined', 'staff_notes' => 'Let us try training first.'])
            ->assertOk();

        $this->assertSame('completed', $this->application->fresh()->status);
        $this->assertDatabaseHas('animals', ['id' => $this->animalId, 'status' => 'adopted']);
        $this->assertSame(4, AdoptionFollowUp::where('status', 'pending')->count());
        $this->assertStringContainsString('Let us try training first.', DB::table('app_notifications')->where('type', 'adoption_return')->value('message'));
    }

    public function test_adopters_are_asked_once_for_an_update_when_a_check_in_comes_due(): void
    {
        $this->complete();

        $this->artisan('post-adoption:ask-for-updates')->assertSuccessful();
        $this->assertSame(0, $this->notified($this->adopter, 'adoption_check_in'));

        $this->travelTo('2026-10-14 08:05:00');
        $this->artisan('post-adoption:ask-for-updates')->assertSuccessful();
        $this->artisan('post-adoption:ask-for-updates')->assertSuccessful();
        $this->assertSame(1, $this->notified($this->adopter, 'adoption_check_in'));
        $this->assertStringContainsString('1 week since Brownie went home with you', DB::table('app_notifications')->where('type', 'adoption_check_in')->value('message'));
    }

    public function test_the_sidebar_badge_counts_what_needs_staff_attention(): void
    {
        $this->complete();
        $this->sendUpdate(['share_publicly' => true]); // unread + a story to review

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/admin/post-adoption/summary')->assertExactJson([
            'check_ins_due' => 0, 'unread_updates' => 1, 'stories_pending' => 1, 'returns_pending' => 0,
        ]);
        $this->getJson('/api/admin/dashboard/pending-counts')->assertJsonPath('post_adoption', 2);
    }
}
