<?php

namespace Tests\Feature;

use App\Models\AnimalLocationLog;
use App\Models\ShelterLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QR-based in-shelter location tracking: staff move animals between named areas (from the
 * page the QR opens, or the admin panel), every move is logged, and only admins manage areas.
 */
class AnimalLocationTest extends TestCase
{
    use RefreshDatabase;

    private function animalId(): int
    {
        return DB::table('animals')->insertGetId([
            'name' => 'Bantay', 'species' => 'dog', 'status' => 'available', 'created_at' => now(),
        ]);
    }

    private function area(string $name): ShelterLocation
    {
        return ShelterLocation::where('name', $name)->firstOrFail();
    }

    public function test_the_shelters_areas_are_there_out_of_the_box(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $names = collect($this->getJson('/api/admin/shelter-locations')->assertOk()->json('locations'))->pluck('name');
        $this->assertEqualsCanonicalizing(['House 1', 'House 2', 'Main Area', 'Kennel 1', 'Kennel 2'], $names->all());
    }

    public function test_staff_move_an_animal_and_each_move_is_logged(): void
    {
        $animal = $this->animalId();
        $staff = User::factory()->staff()->create();
        Sanctum::actingAs($staff);

        $kennel = $this->area('Kennel 1');
        $house = $this->area('House 2');

        $this->postJson("/api/animals/{$animal}/location", ['location_id' => $kennel->id])->assertOk()
            ->assertJsonPath('animal.current_location.name', 'Kennel 1');

        $this->postJson("/api/animals/{$animal}/location", [
            'location_id' => $house->id, 'source' => 'qr_page', 'note' => 'Needs a quieter space',
        ])->assertOk()
            ->assertJsonPath('animal.current_location.name', 'House 2')
            ->assertJsonPath('animal.location_history.0.from.name', 'Kennel 1')
            ->assertJsonPath('animal.location_history.0.to.name', 'House 2')
            ->assertJsonPath('animal.location_history.0.moved_by', $staff->full_name)
            ->assertJsonPath('animal.location_history.0.source', 'qr_page')
            ->assertJsonPath('animal.location_history.0.note', 'Needs a quieter space')
            ->assertJsonPath('animal.location_history.1.from', null);

        $this->assertSame(2, AnimalLocationLog::count());
    }

    public function test_moving_to_the_same_area_is_refused(): void
    {
        $animal = $this->animalId();
        Sanctum::actingAs(User::factory()->staff()->create());
        $kennel = $this->area('Kennel 1');

        $this->postJson("/api/animals/{$animal}/location", ['location_id' => $kennel->id])->assertOk();
        $this->postJson("/api/animals/{$animal}/location", ['location_id' => $kennel->id])->assertStatus(422);
        $this->assertSame(1, AnimalLocationLog::count());
    }

    public function test_regular_users_and_guests_cannot_move_animals_or_see_locations(): void
    {
        $animal = $this->animalId();
        $kennel = $this->area('Kennel 1');

        $this->postJson("/api/animals/{$animal}/location", ['location_id' => $kennel->id])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/animals/{$animal}/location", ['location_id' => $kennel->id])->assertForbidden();
        $this->getJson('/api/admin/shelter-locations')->assertForbidden();

        // The public page (what visitors see when they scan the QR) carries no location.
        $public = $this->getJson("/api/animals/{$animal}")->assertOk()->json('animal');
        $this->assertArrayNotHasKey('current_location', $public);
    }

    public function test_only_admins_manage_areas_and_an_occupied_area_cannot_be_removed(): void
    {
        $animal = $this->animalId();
        Sanctum::actingAs(User::factory()->staff()->create());
        $this->postJson('/api/admin/shelter-locations', ['name' => 'Clinic'])->assertForbidden();
        $this->postJson("/api/animals/{$animal}/location", ['location_id' => $this->area('Kennel 2')->id])->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());
        $clinic = $this->postJson('/api/admin/shelter-locations', ['name' => 'Clinic'])->assertCreated()->json('location');
        $this->postJson('/api/admin/shelter-locations', ['name' => 'Clinic'])->assertStatus(422);
        $this->putJson("/api/admin/shelter-locations/{$clinic['id']}", ['name' => 'Vet Clinic'])->assertOk()
            ->assertJsonPath('location.name', 'Vet Clinic');
        $this->deleteJson("/api/admin/shelter-locations/{$clinic['id']}")->assertOk();

        $this->deleteJson('/api/admin/shelter-locations/'.$this->area('Kennel 2')->id)->assertStatus(409);
    }

    public function test_the_admin_list_shows_and_filters_by_location(): void
    {
        $inKennel = $this->animalId();
        $unassigned = $this->animalId();
        $kennel = $this->area('Kennel 1');
        Sanctum::actingAs(User::factory()->staff()->create());
        $this->postJson("/api/animals/{$inKennel}/location", ['location_id' => $kennel->id])->assertOk();

        $this->getJson("/api/admin/animals?location_id={$kennel->id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inKennel)
            ->assertJsonPath('data.0.current_location.name', 'Kennel 1');

        $this->getJson('/api/admin/animals?location_id=none')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unassigned);
    }
}
