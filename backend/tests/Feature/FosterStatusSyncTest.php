<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Foster lifecycle transitions must keep the animal's public status in sync (audit §4 A-2):
 * starting a foster reserves the animal (→ fostered); ending it releases it (→ available).
 */
class FosterStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:int,1:int} [animalId, fosterId] */
    private function makeFoster(string $animalStatus, string $fosterStatus): array
    {
        $user = User::factory()->create();
        $animalId = DB::table('animals')->insertGetId(['name' => 'Rex', 'species' => 'dog', 'status' => $animalStatus, 'created_at' => now()]);
        $fosterId = DB::table('foster_applications')->insertGetId([
            'user_id' => $user->id,
            'animal_id' => $animalId,
            'status' => $fosterStatus,
            'start_date' => now()->toDateString(),
            'created_at' => now(),
        ]);

        return [$animalId, $fosterId];
    }

    public function test_activating_a_foster_marks_the_animal_fostered(): void
    {
        [$animalId, $fosterId] = $this->makeFoster('available', 'approved');
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->putJson("/api/admin/foster-applications/{$fosterId}", ['status' => 'active'])->assertOk();

        $this->assertSame('fostered', Animal::find($animalId)->status);
    }

    public function test_completing_an_active_foster_releases_the_animal(): void
    {
        [$animalId, $fosterId] = $this->makeFoster('fostered', 'active');
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->putJson("/api/admin/foster-applications/{$fosterId}", ['status' => 'completed'])->assertOk();

        $this->assertSame('available', Animal::find($animalId)->status);
    }

    public function test_declining_a_never_active_foster_leaves_the_animal_untouched(): void
    {
        [$animalId, $fosterId] = $this->makeFoster('available', 'pending');
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->putJson("/api/admin/foster-applications/{$fosterId}", ['status' => 'declined'])->assertOk();

        $this->assertSame('available', Animal::find($animalId)->status);
    }
}
