<?php

namespace Tests\Feature;

use App\Models\AdoptionApplication;
use App\Models\AdoptionFollowUp;
use App\Models\AdoptionReturn;
use App\Models\AdoptionUpdate;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Where an adopted animal is now: staff set an adoption's post-adoption status (in home, lost
 * contact, missing, deceased, rehomed, returned), each change goes on its timeline, and the
 * status drives the check-ins, the animal's record, Happy Tails and what the adopter can do.
 */
class PostAdoptionStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $adopter;

    private User $desk;

    private int $animalId;

    private AdoptionApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-07 10:00:00');

        $this->adopter = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        $this->desk = User::factory()->staff()->create(['full_name' => 'Ana Staff']);
        $this->animalId = DB::table('animals')->insertGetId([
            'name' => 'Brownie', 'species' => 'dog', 'status' => 'adopted', 'created_at' => now(),
        ]);
        $this->application = AdoptionApplication::create([
            'user_id' => $this->adopter->id, 'animal_id' => $this->animalId, 'reference_no' => 'ADP-TEST-1',
            'status' => 'approved', 'full_name' => 'Juan Dela Cruz', 'contact_number' => '09171234567',
        ]);

        Sanctum::actingAs($this->desk);
        $this->putJson("/api/admin/adoption-applications/{$this->application->id}", ['status' => 'completed'])->assertOk();
    }

    private function setStatus(array $payload): TestResponse
    {
        Sanctum::actingAs($this->desk);

        return $this->putJson("/api/admin/post-adoption/adoptions/{$this->application->id}/status", $payload);
    }

    private function animalStatus(): string
    {
        return DB::table('animals')->where('id', $this->animalId)->value('status');
    }

    private function pendingCheckIns(): array
    {
        return AdoptionFollowUp::where('status', 'pending')->orderBy('due_date')->pluck('label')->all();
    }

    public function test_a_completed_adoption_starts_in_home_with_a_timeline(): void
    {
        $this->assertSame('in_home', $this->application->fresh()->post_adoption_status);

        $item = $this->getJson('/api/admin/post-adoption/adoptions')->assertOk()
            ->assertJsonPath('counts.in_home', 1)
            ->assertJsonPath('data.0.post_adoption_status_label', 'In home')
            ->assertJsonPath('data.0.next_check_in.label', '1 week')
            ->json('data.0');
        $this->assertSame([['to' => 'in_home', 'happened_on' => '2026-10-07', 'notes' => 'Adoption completed.']],
            array_map(fn ($h) => array_intersect_key($h, array_flip(['to', 'happened_on', 'notes'])), $item['history']));
    }

    public function test_deceased_closes_the_follow_ups_and_marks_the_animal(): void
    {
        AdoptionUpdate::create([
            'adoption_application_id' => $this->application->id, 'animal_id' => $this->animalId, 'user_id' => $this->adopter->id,
            'wellbeing' => 'doing_well', 'message' => 'All good.', 'share_publicly' => true, 'story_status' => 'approved',
        ]);
        $this->getJson('/api/home/happy-tails')->assertJsonCount(1, 'stories');
        $this->travelTo('2026-11-20 09:00:00');

        $this->setStatus(['status' => 'deceased', 'happened_on' => '2026-11-18', 'notes' => 'Illness; the family called us.'])
            ->assertOk()
            ->assertJsonPath('adoption.post_adoption_status', 'deceased')
            ->assertJsonPath('adoption.history.0.to_label', 'Deceased')
            ->assertJsonPath('adoption.history.0.happened_on', '2026-11-18')
            ->assertJsonPath('adoption.history.0.by', 'Ana Staff');

        $this->assertSame('deceased', $this->animalStatus());
        $this->assertSame('completed', $this->application->fresh()->status, 'still counts as a completed adoption');
        $this->assertSame([], $this->pendingCheckIns());
        $this->assertSame(0, Reminder::where('remindable_type', AdoptionFollowUp::class)->count());
        $this->getJson('/api/home/happy-tails')->assertJsonCount(0, 'stories');
        $this->getJson('/api/animals')->assertJsonMissing(['name' => 'Brownie']);

        // The family can't send updates or a return request; nobody can apply for the animal.
        Sanctum::actingAs($this->adopter);
        $this->getJson('/api/my-adoptions')
            ->assertJsonPath('adoptions.0.post_adoption_status_label', 'Deceased')
            ->assertJsonPath('adoptions.0.can_send_update', false)
            ->assertJsonPath('adoptions.0.can_request_return', false);
        $this->postJson("/api/my-adoptions/{$this->application->id}/updates", ['wellbeing' => 'doing_well', 'message' => 'Hi'])->assertStatus(422);
        $this->postJson("/api/my-adoptions/{$this->application->id}/return", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/animals/{$this->animalId}/adopt", [])->assertStatus(422)
            ->assertJsonPath('message', 'Brownie has passed away, so applications are closed.');

        // Recorded by mistake: back to In home, the animal is Adopted again and the check-ins still ahead return.
        $this->setStatus(['status' => 'in_home', 'notes' => 'Wrong adoption, sorry.'])->assertOk();
        $this->assertSame('adopted', $this->animalStatus());
        $this->assertSame(['3 months', '6 months'], $this->pendingCheckIns());
    }

    public function test_missing_pauses_the_check_ins_until_the_animal_is_found(): void
    {
        $this->travelTo('2026-10-10 09:00:00');
        $this->setStatus(['status' => 'missing', 'notes' => 'Ran off during fireworks.'])->assertOk();
        $this->assertSame([], $this->pendingCheckIns());
        $this->assertSame('adopted', $this->animalStatus());

        // The family can still write in (e.g. to say the dog is back), but can't ask to return it.
        Sanctum::actingAs($this->adopter);
        $this->getJson('/api/my-adoptions')
            ->assertJsonPath('adoptions.0.can_send_update', true)
            ->assertJsonPath('adoptions.0.can_request_return', false);

        $this->travelTo('2026-11-01 09:00:00');
        $this->setStatus(['status' => 'in_home', 'notes' => 'Found by a neighbour.'])->assertOk()
            ->assertJsonPath('adoption.history.0.to', 'in_home')
            ->assertJsonPath('adoption.history.1.to', 'missing');
        $this->assertSame(['1 month', '3 months', '6 months'], $this->pendingCheckIns(), 'the 1-week one had passed');
    }

    public function test_rehomed_ends_the_check_ins_and_lost_contact_keeps_them(): void
    {
        $this->setStatus(['status' => 'lost_contact', 'notes' => 'Number no longer works.'])->assertOk();
        $this->assertCount(4, $this->pendingCheckIns(), 'staff keep trying');

        // The family gets back in touch on their own: the status goes back to In home by itself.
        Sanctum::actingAs($this->adopter);
        $this->postJson("/api/my-adoptions/{$this->application->id}/updates", ['wellbeing' => 'doing_well', 'message' => 'New number!'])->assertCreated();
        $this->assertSame('in_home', $this->application->fresh()->post_adoption_status);
        $this->assertSame('The adopter got back in touch with an update.', $this->application->statusChanges()->first()->notes);

        $this->setStatus(['status' => 'rehomed', 'notes' => 'Given to their cousin in Batangas.'])->assertOk();
        $this->assertSame([], $this->pendingCheckIns());
        $this->assertSame('adopted', $this->animalStatus());
    }

    public function test_staff_can_record_a_return_and_it_settles_a_waiting_request(): void
    {
        $this->setStatus(['status' => 'returned'])->assertStatus(422)
            ->assertJsonPath('message', 'Choose where the animal goes when it comes back.');

        $request = AdoptionReturn::create([
            'adoption_application_id' => $this->application->id, 'animal_id' => $this->animalId,
            'user_id' => $this->adopter->id, 'reason' => 'Moving abroad.', 'status' => 'pending',
        ]);
        $this->setStatus(['status' => 'returned', 'animal_status' => 'quarantine', 'notes' => 'Dropped off today.'])->assertOk()
            ->assertJsonPath('adoption.status', 'returned')
            ->assertJsonPath('adoption.history.0.animal_status', 'quarantine');

        $this->assertSame('quarantine', $this->animalStatus());
        $this->assertSame('accepted', $request->fresh()->status, 'the waiting request is accepted with it');
        $this->assertSame([], $this->pendingCheckIns());
        $this->assertSame(1, $this->application->statusChanges()->where('to_status', 'returned')->count());

        // Returned is the end of this adoption.
        $this->setStatus(['status' => 'in_home'])->assertStatus(422);
    }

    public function test_an_accepted_return_request_goes_on_the_timeline(): void
    {
        $return = AdoptionReturn::create([
            'adoption_application_id' => $this->application->id, 'animal_id' => $this->animalId,
            'user_id' => $this->adopter->id, 'reason' => 'Allergies.', 'status' => 'pending',
        ]);
        $this->putJson("/api/admin/post-adoption/returns/{$return->id}", ['decision' => 'accepted', 'animal_status' => 'available'])->assertOk();

        $this->assertSame('returned', $this->application->fresh()->post_adoption_status);
        $this->getJson('/api/admin/post-adoption/adoptions?status=returned')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.history.0.notes', 'Return request accepted.');
    }

    public function test_the_change_is_checked(): void
    {
        $this->setStatus(['status' => 'in_home'])->assertStatus(422)->assertJsonPath('message', 'That is already this adoption’s status.');
        $this->setStatus(['status' => 'admitted'])->assertStatus(422);
        $this->setStatus(['status' => 'missing', 'happened_on' => '2026-10-08'])->assertStatus(422);   // tomorrow
        $this->setStatus(['status' => 'missing', 'happened_on' => '2026-09-30'])->assertStatus(422)
            ->assertJsonPath('message', 'The date can’t be before the adoption was completed.');

        $this->setStatus(['status' => 'deceased'])->assertOk();
        $this->setStatus(['status' => 'returned', 'animal_status' => 'available'])->assertStatus(422);
        $this->assertSame('deceased', $this->animalStatus());

        // Not completed yet: no post-adoption status to set.
        $pending = AdoptionApplication::create([
            'user_id' => $this->adopter->id, 'animal_id' => $this->animalId, 'reference_no' => 'ADP-TEST-2', 'status' => 'pending',
            'full_name' => 'Juan Dela Cruz', 'contact_number' => '09171234567',
        ]);
        $this->putJson("/api/admin/post-adoption/adoptions/{$pending->id}/status", ['status' => 'missing'])->assertStatus(422);
    }

    public function test_the_list_filters_by_status_and_searches(): void
    {
        $this->setStatus(['status' => 'missing'])->assertOk();

        $this->getJson('/api/admin/post-adoption/adoptions?status=missing')->assertJsonCount(1, 'data')
            ->assertJsonPath('counts.missing', 1)->assertJsonPath('counts.in_home', 0);
        $this->getJson('/api/admin/post-adoption/adoptions?status=in_home')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/post-adoption/adoptions?q=brown')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/post-adoption/adoptions?q=juan')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/post-adoption/adoptions?q=nobody')->assertJsonCount(0, 'data');
    }
}
