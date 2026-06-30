<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The aggregated dashboard pending-counts endpoint replaces 7 separate per-resource polls
 * (audit §11). It must report each "needs attention" count and stay staff-gated.
 */
class DashboardPendingCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_counts_aggregates_each_resource(): void
    {
        $member = User::factory()->create();
        $animalId = DB::table('animals')->insertGetId(['name' => 'Rex', 'species' => 'dog', 'status' => 'available', 'created_at' => now()]);

        DB::table('rescue_reports')->insert(['location' => 'Pasig', 'urgency' => 'high', 'status' => 'pending', 'read_at' => null, 'created_at' => now()]);
        DB::table('foster_applications')->insert(['user_id' => $member->id, 'animal_id' => $animalId, 'status' => 'pending', 'created_at' => now()]);
        DB::table('reminders')->insert(['animal_id' => $animalId, 'title' => 'Booster due', 'reminder_date' => now()->subDay()->toDateString(), 'status' => 'pending', 'created_at' => now()]);

        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/admin/dashboard/pending-counts')
            ->assertOk()
            ->assertJsonPath('rescue', 1)
            ->assertJsonPath('foster', 1)
            ->assertJsonPath('reminders_overdue', 1)
            ->assertJsonPath('adoption', 0)
            ->assertJsonPath('donation', 0)
            ->assertJsonPath('visitation', 0)
            ->assertJsonPath('volunteer', 0);
    }

    public function test_pending_counts_requires_staff(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/dashboard/pending-counts')->assertStatus(403);
    }
}
