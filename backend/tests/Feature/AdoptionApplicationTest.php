<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdoptionApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function seedApplications(): void
    {
        $applicant = User::factory()->create();
        $animal = DB::table('animals')->insertGetId(['name' => 'Rex', 'species' => 'dog', 'status' => 'available', 'created_at' => now()]);

        foreach (['pending', 'declined', 'approved', 'completed'] as $i => $status) {
            DB::table('adoption_applications')->insert([
                'user_id' => $applicant->id,
                'animal_id' => $animal,
                'reference_no' => 'ADP-'.$i,
                'status' => $status,
                'created_at' => now(),
            ]);
        }
    }

    public function test_admin_inbox_excludes_decided_applications(): void
    {
        $this->seedApplications();
        Sanctum::actingAs(User::factory()->staff()->create());

        $res = $this->getJson('/api/admin/adoption-applications?exclude_decided=1')->assertOk();

        $statuses = collect($res->json('data'))->pluck('status')->all();
        $this->assertEqualsCanonicalizing(['pending', 'declined'], $statuses, 'inbox should drop approved/completed');
        $this->assertSame(2, $res->json('total'));
    }

    public function test_admin_index_without_flag_still_returns_all_statuses(): void
    {
        $this->seedApplications();
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/admin/adoption-applications')
            ->assertOk()
            ->assertJsonPath('total', 4);
    }

    public function test_admin_index_explicit_status_filter_still_works(): void
    {
        $this->seedApplications();
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/admin/adoption-applications?status=approved')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'approved');
    }
}
