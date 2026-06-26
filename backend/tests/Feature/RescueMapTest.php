<?php

namespace Tests\Feature;

use App\Models\RescueReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RescueMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_submit_a_report_with_coordinates(): void
    {
        $this->postJson('/api/rescue-reports', [
            'location' => 'Near the market',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'urgency' => 'high',
        ])->assertCreated();

        $this->assertDatabaseHas('rescue_reports', ['latitude' => 14.5995, 'longitude' => 120.9842]);
    }

    public function test_map_endpoint_returns_only_reports_with_coordinates(): void
    {
        RescueReport::create(['location' => 'A', 'urgency' => 'low', 'status' => 'pending', 'latitude' => 14.6, 'longitude' => 121.0, 'created_at' => now()]);
        RescueReport::create(['location' => 'B (no pin)', 'urgency' => 'low', 'status' => 'pending', 'created_at' => now()]);

        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/rescue-reports/map')
            ->assertOk()
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.location', 'A');
    }

    public function test_map_endpoint_requires_staff(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/rescue-reports/map')->assertStatus(403);
    }
}
