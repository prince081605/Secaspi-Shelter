<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_load_analytics_overview(): void
    {
        Animal::create(['name' => 'A', 'species' => 'dog', 'status' => 'available']);
        Animal::create(['name' => 'B', 'species' => 'cat', 'status' => 'adopted']);

        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/admin/analytics/overview')
            ->assertOk()
            ->assertJsonStructure([
                'summary' => ['total_animals', 'available', 'adopted', 'verified_donations_total'],
                'flow_by_month',
                'donations_by_month',
                'species_mix',
                'avg_length_of_stay_days',
                'live_release_rate',
            ])
            ->assertJsonPath('summary.total_animals', 2);
    }

    public function test_non_staff_is_blocked(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/analytics/overview')->assertStatus(403);
    }
}
