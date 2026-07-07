<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the donor-chosen expense category and the per-category spillover allocation surfaced on
 * the public Transparency board (App\Support\DonationCategories).
 */
class DonationCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_a_valid_category(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/donations', [
            'amount' => 500,
            'payment_method' => 'cash',
            'category' => 'animal_care',
        ])
            ->assertCreated()
            ->assertJsonPath('donation.category', 'animal_care');
    }

    public function test_store_allows_no_category_meaning_where_needed_most(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/donations', [
            'amount' => 500,
            'payment_method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('donation.category', null);
    }

    public function test_store_rejects_an_unknown_category(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/donations', [
            'amount' => 500,
            'payment_method' => 'cash',
            'category' => 'yacht_fund',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_transparency_caps_a_funded_category_and_spills_the_surplus(): void
    {
        $donor = User::factory()->create();

        // Transportation (goal 720) is massively over-funded; animal_care (goal 35,500) is short.
        // A "needed most" (null category) gift adds to the redistributable pool.
        DB::table('donations')->insert([
            ['user_id' => $donor->id, 'reference_no' => 'C1', 'amount' => 5000, 'payment_method' => 'gcash', 'category' => 'transportation', 'status' => 'verified', 'is_anonymous' => true, 'donated_at' => now()],
            ['user_id' => $donor->id, 'reference_no' => 'C2', 'amount' => 1000, 'payment_method' => 'gcash', 'category' => 'animal_care', 'status' => 'verified', 'is_anonymous' => true, 'donated_at' => now()],
            ['user_id' => $donor->id, 'reference_no' => 'C3', 'amount' => 2000, 'payment_method' => 'gcash', 'category' => null, 'status' => 'verified', 'is_anonymous' => true, 'donated_at' => now()],
            // A pending gift must not count toward the allocation.
            ['user_id' => $donor->id, 'reference_no' => 'C4', 'amount' => 9999, 'payment_method' => 'gcash', 'category' => 'animal_care', 'status' => 'pending', 'is_anonymous' => true, 'donated_at' => now()],
        ]);

        $res = $this->getJson('/api/home/transparency')->assertOk();

        // Order follows DonationCategories::DEFAULTS: [personnel, facility, transportation, animal_care, cleaning].
        $res->assertJsonPath('categories.2.key', 'transportation')
            ->assertJsonPath('categories.2.allocated', 720)   // capped at its goal
            ->assertJsonPath('categories.2.funded', true)
            ->assertJsonPath('categories.3.key', 'animal_care')
            ->assertJsonPath('categories.3.allocated', 7280)  // 1000 direct + (4280 surplus + 2000 needed-most)
            ->assertJsonPath('category_redistributed', 6280)
            ->assertJsonPath('category_surplus', 0);
    }
}
