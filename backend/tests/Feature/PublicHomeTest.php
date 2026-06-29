<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the public home analytics endpoints after their §0.2 extraction from inline route
 * closures into PublicHomeController (+ the shared PublicStats helper). These endpoints had no
 * direct coverage before; these tests lock in the moved behaviour.
 */
class PublicHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_stats_counts_records(): void
    {
        $user = User::factory()->create();
        $animal = DB::table('animals')->insertGetId(['name' => 'Rex', 'species' => 'dog', 'status' => 'available', 'created_at' => now()]);
        DB::table('adoption_applications')->insert([
            ['user_id' => $user->id, 'animal_id' => $animal, 'status' => 'completed', 'created_at' => now()],
            ['user_id' => $user->id, 'animal_id' => $animal, 'status' => 'pending', 'created_at' => now()],
        ]);
        DB::table('medical_records')->insert(['animal_id' => $animal, 'type' => 'checkup']);
        DB::table('rescue_reports')->insert(['location' => 'Pasig', 'status' => 'pending', 'created_at' => now()]);

        $this->getJson('/api/home/stats')
            ->assertOk()
            ->assertJsonPath('items.0.value', '1+')       // animals helped
            ->assertJsonPath('items.1.value', '1+')       // adoptions completed (pending ignored)
            ->assertJsonPath('items.2.value', '1+')       // medical treatments
            ->assertJsonPath('items.3.value', '1+');      // rescue reports
    }

    public function test_home_impact_masks_top_donors(): void
    {
        $donor = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        DB::table('donations')->insert([
            ['user_id' => $donor->id, 'reference_no' => 'I1', 'amount' => 1500, 'payment_method' => 'gcash', 'status' => 'verified', 'is_anonymous' => false, 'donated_at' => now()],
            // anonymous + pending are excluded from the leaderboard
            ['user_id' => $donor->id, 'reference_no' => 'I2', 'amount' => 9000, 'payment_method' => 'gcash', 'status' => 'verified', 'is_anonymous' => true, 'donated_at' => now()],
            ['user_id' => $donor->id, 'reference_no' => 'I3', 'amount' => 7000, 'payment_method' => 'gcash', 'status' => 'pending', 'is_anonymous' => false, 'donated_at' => now()],
        ]);

        $this->getJson('/api/home/impact')
            ->assertOk()
            ->assertJsonPath('donations_raised', 10500)   // both verified, anonymous included in total
            ->assertJsonPath('top_donors.0.name', 'Juan C.')
            ->assertJsonPath('top_donors.0.total', 1500); // only the named verified gift
    }

    public function test_home_transparency_totals_and_masks_recent_donors(): void
    {
        $donor = User::factory()->create(['full_name' => 'Maria Santos']);
        DB::table('donations')->insert([
            // Distinct timestamps so the "newest first" ordering is deterministic.
            ['user_id' => $donor->id, 'reference_no' => 'T1', 'amount' => 2000, 'payment_method' => 'gcash', 'status' => 'verified', 'is_anonymous' => false, 'donated_at' => now()->subMinutes(5)],
            ['user_id' => $donor->id, 'reference_no' => 'T2', 'amount' => 500, 'payment_method' => 'cash', 'status' => 'verified', 'is_anonymous' => true, 'donated_at' => now()],
        ]);

        $this->getJson('/api/home/transparency')
            ->assertOk()
            ->assertJsonPath('total_raised', 2500)
            ->assertJsonPath('this_month_raised', 2500)
            ->assertJsonPath('monthly_goal', 80220)        // default when unset
            ->assertJsonPath('recent_donations.0.name', 'Anonymous')   // newest first = the anonymous gift
            ->assertJsonPath('recent_donations.1.name', 'Maria S.');
    }

    public function test_home_featured_animals_returns_one_card_per_animal(): void
    {
        $animal = DB::table('animals')->insertGetId(['name' => 'Buddy', 'species' => 'dog', 'age' => 2, 'status' => 'available', 'created_at' => now()]);
        // Two photo rows for the same animal must not fan out into duplicate cards.
        DB::table('animal_photos')->insert([
            ['animal_id' => $animal, 'photo_url' => 'animals/secondary.jpg', 'is_main' => false],
            ['animal_id' => $animal, 'photo_url' => 'animals/main.jpg', 'is_main' => true],
        ]);
        // Adopted animals are excluded from the featured strip.
        DB::table('animals')->insert(['name' => 'Gone', 'species' => 'cat', 'status' => 'adopted', 'created_at' => now()]);

        $res = $this->getJson('/api/home/featured-animals')->assertOk();

        $animals = $res->json('animals');
        $this->assertCount(1, $animals);
        $this->assertSame('Buddy', $animals[0]['name']);
        $this->assertSame('2 yrs', $animals[0]['age']);
        $this->assertStringContainsString('animals/main.jpg', $animals[0]['photo']); // prefers is_main
    }
}
