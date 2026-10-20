<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bulk photo update: file names are matched to animals by ID or name before any photo is sent,
 * and a new photo can be made the one shown first.
 */
class AnimalPhotoMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_names_match_by_id_or_name(): void
    {
        Sanctum::actingAs(User::factory()->deskAdmin()->create());
        $brownie = Animal::create(['name' => 'Brownie', 'species' => 'dog', 'status' => 'available']);
        $mingDog = Animal::create(['name' => 'Ming', 'species' => 'dog', 'status' => 'available']);
        Animal::create(['name' => 'Ming', 'species' => 'cat', 'status' => 'available']);
        $max2 = Animal::create(['name' => 'Max 2', 'species' => 'dog', 'status' => 'available']);

        $files = ['brownie.jpg', 'Brownie-2.JPG', 'brownie (3).png', "{$mingDog->id}.jpg", "#{$brownie->id}-front.webp", 'Ming.jpg', 'Max_2.jpg', 'Unknown.jpg'];
        $response = $this->postJson('/api/admin/animals/photos/match', ['files' => $files])->assertOk();

        $how = collect($response->json('matches'))->mapWithKeys(fn ($m) => [$m['file'] => [$m['how'], $m['animal']['id'] ?? null]])->all();
        $this->assertSame([
            'brownie.jpg' => ['name', $brownie->id],
            'Brownie-2.JPG' => ['name', $brownie->id],
            'brownie (3).png' => ['name', $brownie->id],
            "{$mingDog->id}.jpg" => ['id', $mingDog->id],
            "#{$brownie->id}-front.webp" => ['id', $brownie->id],
            'Ming.jpg' => ['ambiguous', null],
            'Max_2.jpg' => ['name', $max2->id],
            'Unknown.jpg' => ['none', null],
        ], $how);
        $this->assertCount(2, $response->json('matches.5.candidates'));
        $this->assertCount(4, $response->json('animals'));
    }

    public function test_only_desk_admins_match_photos(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());
        $this->postJson('/api/admin/animals/photos/match', ['files' => ['a.jpg']])->assertForbidden();
    }

    public function test_a_new_photo_can_become_the_main_one(): void
    {
        Storage::fake();
        Sanctum::actingAs(User::factory()->deskAdmin()->create());
        $animal = Animal::create(['name' => 'Brownie', 'species' => 'dog', 'status' => 'available']);

        $this->post("/api/animals/{$animal->id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/animals/{$animal->id}/photos", ['photos' => [UploadedFile::fake()->image('b.jpg')]], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame([true, false], $animal->photos()->orderBy('id')->pluck('is_main')->map(fn ($v) => (bool) $v)->all());

        $this->post("/api/animals/{$animal->id}/photos", ['photos' => [UploadedFile::fake()->image('c.jpg')], 'make_main' => 1], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame([false, false, true], $animal->photos()->orderBy('id')->pluck('is_main')->map(fn ($v) => (bool) $v)->all());
    }
}
