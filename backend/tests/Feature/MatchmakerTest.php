<?php

namespace Tests\Feature;

use App\Models\Animal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchmakerTest extends TestCase
{
    use RefreshDatabase;

    private array $answers = [
        'home_type' => 'apartment',
        'activity_level' => 'low',
        'experience' => 'first_time',
        'household' => 'kids',
        'preferred_species' => 'dog',
        'preferred_size' => 'any',
    ];

    public function test_it_is_public_and_ranks_animals_by_match(): void
    {
        // A calm, small, no-issue dog should outrank a high-energy aggressive large dog
        // for a low-activity, first-time, apartment, kids household.
        Animal::create([
            'name' => 'Gentle', 'species' => 'dog', 'size' => 'small', 'status' => 'available',
            'behavioral_assessment' => [],
        ]);
        Animal::create([
            'name' => 'Rowdy', 'species' => 'dog', 'size' => 'large', 'status' => 'available',
            'behavioral_assessment' => ['excessive energy', 'food aggression'],
        ]);
        // Not available -> excluded.
        Animal::create(['name' => 'Gone', 'species' => 'dog', 'status' => 'adopted']);

        $res = $this->postJson('/api/matchmaker', $this->answers)->assertOk();

        $matches = $res->json('matches');
        $this->assertCount(2, $matches);
        $this->assertSame('Gentle', $matches[0]['animal']['name']);
        $this->assertGreaterThan($matches[1]['percent'], $matches[0]['percent']);
        $this->assertNotEmpty($matches[0]['reasons']);
        $this->assertNotEmpty($matches[1]['cautions']);
    }

    public function test_species_preference_penalises_mismatch(): void
    {
        Animal::create(['name' => 'Whiskers', 'species' => 'cat', 'size' => 'small', 'status' => 'available', 'behavioral_assessment' => []]);

        $res = $this->postJson('/api/matchmaker', $this->answers)->assertOk();
        // A cat when a dog was requested should score lower than its neutral baseline.
        $this->assertLessThan(70, $res->json('matches.0.percent'));
    }

    public function test_it_validates_required_answers(): void
    {
        $this->postJson('/api/matchmaker', [])->assertStatus(422);
    }
}
