<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Volunteer applications, and the valid/school ID an applicant now has to present.
 *
 * Volunteers handle animals and meet the public at adoption events, so the shelter records who
 * they are. That makes the ID a submission requirement, and makes where the ID is readable a
 * thing worth pinning down — hence the last test here.
 */
class VolunteerApplicationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A fake photo of an ID.
     *
     * `create()` with an explicit mime rather than `image()`: the latter renders a real image and
     * so needs the GD extension, which is not installed on every dev machine here. The `image`
     * validation rule checks the mime type, so this satisfies it either way.
     */
    private function idPhoto(string $name = 'school-id.jpg', int $kilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/jpeg');
    }

    /** @return array<string, mixed> A valid application payload. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'availability' => 'Weekends',
            'experience' => 'Fostered two dogs last year.',
            'reason' => 'I want to help the Aspins in the shelter.',
            'valid_id_type' => 'school_id',
            'valid_id_number' => '2021-00456',
            'valid_id_image' => $this->idPhoto(),
        ], $overrides);
    }

    // ---- Submitting -----------------------------------------------------------------------

    public function test_an_applicant_can_apply_with_a_valid_id(): void
    {
        // Bare fake() so the default disk is the one faked — that is where store() writes,
        // since the controller passes no disk argument.
        Storage::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/volunteer-applications', $this->payload())
            ->assertStatus(201);

        $application = VolunteerApplication::sole();
        $this->assertSame('school_id', $application->valid_id_type);
        $this->assertSame('2021-00456', $application->valid_id_number);
        $this->assertNotNull($application->valid_id_path);
        Storage::assertExists($application->valid_id_path);
    }

    public function test_the_id_type_number_and_photo_are_all_required(): void
    {
        Storage::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/volunteer-applications', [
            'availability' => 'Weekends',
            'reason' => 'I want to help.',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['valid_id_type', 'valid_id_number', 'valid_id_image']);

        $this->assertSame(0, VolunteerApplication::count());
    }

    public function test_an_unknown_id_type_is_rejected(): void
    {
        Storage::fake();
        Sanctum::actingAs(User::factory()->create());

        // Guards the hand-kept pairing with frontend/src/lib/validIdTypes.js: if the two lists
        // drift, a type the form offers stops being one the API accepts.
        $this->postJson('/api/volunteer-applications', $this->payload(['valid_id_type' => 'library_card']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_id_type']);
    }

    public function test_the_id_photo_must_be_an_image_within_the_size_limit(): void
    {
        Storage::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/volunteer-applications', $this->payload([
            'valid_id_image' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['valid_id_image']);

        // 6 MB, over the 5120 KB rule the form promises.
        $this->postJson('/api/volunteer-applications', $this->payload([
            'valid_id_image' => $this->idPhoto('huge.jpg', 6000),
        ]))->assertStatus(422)->assertJsonValidationErrors(['valid_id_image']);
    }

    // ---- The two guards that were never covered -------------------------------------------

    public function test_an_existing_volunteer_cannot_apply_again(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        Volunteer::create(['user_id' => $user->id, 'type' => 'volunteer']);
        Sanctum::actingAs($user);

        $this->postJson('/api/volunteer-applications', $this->payload())->assertStatus(409);

        // The guard runs before the upload, so a refused application leaves nothing behind.
        $this->assertSame(0, VolunteerApplication::count());
        Storage::assertDirectoryEmpty('volunteer-ids');
    }

    public function test_a_second_pending_application_is_refused(): void
    {
        Storage::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/volunteer-applications', $this->payload())->assertStatus(201);
        $this->postJson('/api/volunteer-applications', $this->payload())->assertStatus(409);

        $this->assertSame(1, VolunteerApplication::count());
    }

    // ---- Who can read the ID --------------------------------------------------------------

    public function test_staff_see_the_id_but_the_applicant_is_not_sent_their_own_back(): void
    {
        Storage::fake();
        $applicant = User::factory()->create();
        Sanctum::actingAs($applicant);
        $this->postJson('/api/volunteer-applications', $this->payload())->assertStatus(201);

        // The applicant's own list: no ID echoed back over the wire.
        $mine = $this->getJson('/api/volunteer-applications')->assertOk()->json('applications.0');
        $this->assertArrayNotHasKey('valid_id_url', $mine);
        $this->assertArrayNotHasKey('valid_id_number', $mine);

        // Staff reviewing the queue: the ID is the point.
        Sanctum::actingAs(User::factory()->staff()->create());
        $row = $this->getJson('/api/admin/volunteer-applications')->assertOk()->json('data.0');
        $this->assertSame('school_id', $row['valid_id_type']);
        $this->assertSame('2021-00456', $row['valid_id_number']);
        $this->assertNotNull($row['valid_id_url']);
    }
}
