<?php

namespace Tests\Feature;

use App\Models\AdoptionApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An adoption applicant presents a valid ID (type + photo), which the staff reviewing the
 * application can see.
 */
class AdoptionValidIdTest extends TestCase
{
    use RefreshDatabase;

    private function animalId(): int
    {
        return DB::table('animals')->insertGetId([
            'name' => 'Rex', 'species' => 'dog', 'status' => 'available', 'created_at' => now(),
        ]);
    }

    /** create() with an explicit mime: image() needs GD, which not every dev machine has. */
    private function idPhoto(int $kilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->create('id.jpg', $kilobytes, 'image/jpeg');
    }

    private function form(array $overrides = []): array
    {
        return [
            'full_name' => 'Juan Dela Cruz',
            'contact_number' => '09171234567',
            'address' => 'Calamba, Laguna',
            'reason' => 'We have a fenced yard and time for a dog.',
            'valid_id_type' => 'national_id',
            'valid_id_image' => $this->idPhoto(),
            ...$overrides,
        ];
    }

    public function test_an_application_stores_the_id_and_staff_can_see_it(): void
    {
        Storage::fake();
        $animal = $this->animalId();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/animals/{$animal}/adopt", $this->form())->assertCreated();

        $application = AdoptionApplication::first();
        $this->assertSame('national_id', $application->valid_id_type);
        Storage::assertExists($application->valid_id_path);

        Sanctum::actingAs(User::factory()->staff()->create());
        $row = $this->getJson('/api/admin/adoption-applications')->assertOk()->json('data.0');
        $this->assertSame('national_id', $row['valid_id_type']);
        $this->assertNotNull($row['valid_id_url']);
    }

    public function test_the_id_type_and_photo_are_required(): void
    {
        Storage::fake();
        $animal = $this->animalId();
        Sanctum::actingAs(User::factory()->create());

        $form = $this->form();
        unset($form['valid_id_type'], $form['valid_id_image']);

        $this->postJson("/api/animals/{$animal}/adopt", $form)
            ->assertStatus(422)->assertJsonValidationErrors(['valid_id_type', 'valid_id_image']);
        $this->assertSame(0, AdoptionApplication::count());
    }

    public function test_an_unknown_id_type_or_oversized_photo_is_refused(): void
    {
        Storage::fake();
        $animal = $this->animalId();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/animals/{$animal}/adopt", $this->form(['valid_id_type' => 'library_card']))
            ->assertStatus(422)->assertJsonValidationErrors(['valid_id_type']);

        $this->postJson("/api/animals/{$animal}/adopt", $this->form(['valid_id_image' => $this->idPhoto(6000)]))
            ->assertStatus(422)->assertJsonValidationErrors(['valid_id_image']);
    }

    public function test_a_duplicate_application_does_not_leave_an_orphaned_id_photo(): void
    {
        Storage::fake();
        $animal = $this->animalId();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/animals/{$animal}/adopt", $this->form())->assertCreated();
        $this->postJson("/api/animals/{$animal}/adopt", $this->form())->assertStatus(409);

        $this->assertCount(1, Storage::allFiles('adoption-ids'));
    }
}
