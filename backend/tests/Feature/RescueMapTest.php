<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
