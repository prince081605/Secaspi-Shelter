<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin animal stat strip is backed by one grouped-count endpoint instead of 4 full-list
 * requests (audit §3). A 200 here also confirms /admin/animals/stats resolves to adminStats
 * rather than being captured by the /admin/animals/{animal} model binding.
 */
class AnimalStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_animal_stats_counts_by_status(): void
    {
        DB::table('animals')->insert([
            ['name' => 'A', 'species' => 'dog', 'status' => 'available', 'created_at' => now()],
            ['name' => 'B', 'species' => 'dog', 'status' => 'available', 'created_at' => now()],
            ['name' => 'C', 'species' => 'cat', 'status' => 'adopted', 'created_at' => now()],
            ['name' => 'D', 'species' => 'dog', 'status' => 'archived', 'created_at' => now()],
            ['name' => 'E', 'species' => 'dog', 'status' => 'medical', 'created_at' => now()],
        ]);

        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/admin/animals/stats')
            ->assertOk()
            ->assertJsonPath('total', 5)       // all statuses, incl. medical/archived
            ->assertJsonPath('available', 2)
            ->assertJsonPath('adopted', 1)
            ->assertJsonPath('archived', 1);
    }
}
