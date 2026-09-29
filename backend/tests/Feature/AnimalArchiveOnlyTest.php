<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Animals are archived, never deleted: a delete used to cascade away the animal's medical
 * history, vaccinations and adoption/foster applications along with it.
 */
class AnimalArchiveOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function animalId(): int
    {
        return DB::table('animals')->insertGetId([
            'name' => 'Bantay', 'species' => 'dog', 'status' => 'available', 'created_at' => now(),
        ]);
    }

    public function test_there_is_no_way_to_delete_an_animal_even_as_admin(): void
    {
        $id = $this->animalId();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/animals/{$id}")->assertStatus(405);

        $this->assertDatabaseHas('animals', ['id' => $id]);
    }

    public function test_an_animal_can_be_archived_and_restored(): void
    {
        $id = $this->animalId();
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->postJson("/api/animals/{$id}/archive")->assertOk();
        $this->assertDatabaseHas('animals', ['id' => $id, 'status' => 'archived']);

        $this->putJson("/api/animals/{$id}", ['status' => 'available'])->assertOk();
        $this->assertDatabaseHas('animals', ['id' => $id, 'status' => 'available']);
    }
}
