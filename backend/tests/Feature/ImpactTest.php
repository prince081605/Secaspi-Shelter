<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImpactTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_impact_reflects_donations_and_badges(): void
    {
        $user = User::factory()->create();
        Setting::setMany(['cost_per_meal' => '25']);

        DB::table('donations')->insert([
            ['user_id' => $user->id, 'reference_no' => 'D1', 'amount' => 600, 'payment_method' => 'gcash', 'status' => 'verified', 'is_anonymous' => true, 'donated_at' => now()],
            ['user_id' => $user->id, 'reference_no' => 'D2', 'amount' => 600, 'payment_method' => 'gcash', 'status' => 'verified', 'is_anonymous' => true, 'donated_at' => now()],
            // pending one is ignored
            ['user_id' => $user->id, 'reference_no' => 'D3', 'amount' => 999, 'payment_method' => 'gcash', 'status' => 'pending', 'is_anonymous' => true, 'donated_at' => now()],
        ]);

        Sanctum::actingAs($user);

        $res = $this->getJson('/api/impact/me')->assertOk();

        $res->assertJsonPath('donated_total', 1200)
            ->assertJsonPath('donation_count', 2)
            ->assertJsonPath('meals_funded', 48); // 1200 / 25

        // "Generous Heart" (>=1000) should be earned; "Shelter Champion" (>=5000) should not.
        $badges = collect($res->json('badges'))->keyBy('key');
        $this->assertTrue($badges['generous']['earned']);
        $this->assertFalse($badges['champion']['earned']);
        $this->assertTrue($badges['first_gift']['earned']);
    }

    public function test_leaderboard_is_public_and_masks_names(): void
    {
        $donor = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        DB::table('donations')->insert([
            'user_id' => $donor->id, 'reference_no' => 'D9', 'amount' => 2000, 'payment_method' => 'gcash',
            'status' => 'verified', 'is_anonymous' => false, 'donated_at' => now(),
        ]);

        $this->getJson('/api/impact/leaderboard')
            ->assertOk()
            ->assertJsonPath('top_donors.0.name', 'Juan C.')
            ->assertJsonPath('top_donors.0.total', 2000);
    }
}
