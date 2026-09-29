<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Species is a dog/cat dropdown in the admin form; the API holds the same line, stores it
 * lowercase, and doesn't break editing an animal recorded before the rule existed.
 */
class AnimalSpeciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_animal_must_be_a_dog_or_a_cat(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->postJson('/api/animals', ['name' => 'Mingming', 'species' => 'Cat'])->assertCreated();
        $this->assertDatabaseHas('animals', ['name' => 'Mingming', 'species' => 'cat']);

        $this->postJson('/api/animals', ['name' => 'Hoppy', 'species' => 'rabbit'])
            ->assertStatus(422)->assertJsonValidationErrors(['species']);
    }

    public function test_an_intake_species_is_dog_cat_or_left_blank(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->postJson('/api/admin/intakes', ['intake_type' => 'stray', 'species' => 'Dog'])->assertCreated();
        $this->assertDatabaseHas('intakes', ['species' => 'dog']);

        $this->postJson('/api/admin/intakes', ['intake_type' => 'stray'])->assertCreated();

        $this->postJson('/api/admin/intakes', ['intake_type' => 'stray', 'species' => 'bird'])
            ->assertStatus(422)->assertJsonValidationErrors(['species']);
    }

    public function test_an_older_animal_keeps_its_species_when_edited_but_cannot_gain_a_new_one(): void
    {
        $id = DB::table('animals')->insertGetId([
            'name' => 'Hoppy', 'species' => 'Rabbit', 'status' => 'available', 'created_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->putJson("/api/animals/{$id}", ['species' => 'rabbit', 'breed' => 'Lop'])->assertOk();
        $this->putJson("/api/animals/{$id}", ['species' => 'hamster'])
            ->assertStatus(422)->assertJsonValidationErrors(['species']);
        $this->putJson("/api/animals/{$id}", ['species' => 'dog'])->assertOk();
    }
}
