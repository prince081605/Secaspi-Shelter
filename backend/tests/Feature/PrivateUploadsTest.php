<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Intake;
use App\Models\IntakeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Donation proofs, rescue photos, and intake documents must live on the `private` disk (never the
 * public/default disk, so they're unreachable through the public /storage URL) and be served only
 * via short-lived signed URLs to already-authorized viewers (audit §5/6/7 E, Appendix A3 #4).
 *
 * `Storage::fake()` (no arg) fakes the default/public disk; `Storage::fake('private')` the private one.
 */
class PrivateUploadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_donation_proof_is_private_and_served_via_a_signed_url(): void
    {
        Storage::fake();
        Storage::fake('private');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/donations', [
            'amount' => 500,
            'payment_method' => 'gcash',
            'proof_image' => UploadedFile::fake()->create('proof.jpg', 100, 'image/jpeg'),
        ])->assertStatus(201);

        $donation = Donation::where('user_id', $user->id)->firstOrFail();

        Storage::disk('private')->assertExists($donation->proof_image);
        Storage::assertMissing($donation->proof_image); // not on the public/default disk

        $shown = $this->getJson("/api/donations/{$donation->id}")
            ->assertOk()
            ->json('donation.proof_image');

        // Storage::fake()'s temporaryUrl() stub appends "?expiration=..." rather than a real
        // "?signature=..." (that requires the actual signed route / S3 presign) — its presence
        // still proves temporaryUrl() was called instead of the unsigned Storage::url().
        $this->assertStringContainsString('expiration=', $shown);
    }

    public function test_rescue_report_photo_is_private_and_served_via_a_signed_url(): void
    {
        Storage::fake();
        Storage::fake('private');

        $this->postJson('/api/rescue-reports', [
            'location' => 'Barangay Test',
            'urgency' => 'medium',
            'photo' => UploadedFile::fake()->create('rescue.jpg', 100, 'image/jpeg'),
        ])->assertStatus(201);

        $report = \App\Models\RescueReport::firstOrFail();

        Storage::disk('private')->assertExists($report->photo_url);
        Storage::assertMissing($report->photo_url);

        Sanctum::actingAs(User::factory()->staff()->create());

        $shown = $this->getJson('/api/rescue-reports')
            ->assertOk()
            ->json('data.0.photo_url');

        $this->assertStringContainsString('expiration=', $shown);
    }

    public function test_intake_document_is_private_and_served_via_a_signed_url(): void
    {
        Storage::fake();
        Storage::fake('private');

        Sanctum::actingAs(User::factory()->staff()->create());

        $this->postJson('/api/admin/intakes', [
            'intake_type' => 'stray',
            'documents' => [UploadedFile::fake()->create('doc.jpg', 100, 'image/jpeg')],
        ])->assertStatus(201);

        $document = IntakeDocument::firstOrFail();

        Storage::disk('private')->assertExists($document->file_path);
        Storage::assertMissing($document->file_path);

        $shown = $this->getJson("/api/admin/intakes/{$document->intake_id}")
            ->assertOk()
            ->json('intake.documents.0.file_path');

        $this->assertStringContainsString('expiration=', $shown);
    }

    public function test_intake_conversion_copies_the_private_document_to_the_public_animal_photo(): void
    {
        Storage::fake();
        Storage::fake('private');

        Sanctum::actingAs(User::factory()->staff()->create());

        $intake = Intake::create(['intake_type' => 'stray', 'animal_name' => 'Rex']);
        $path = UploadedFile::fake()->create('doc.jpg', 100, 'image/jpeg')->store('intakes', 'private');
        IntakeDocument::create(['intake_id' => $intake->id, 'file_path' => $path, 'original_name' => 'doc.jpg']);

        $response = $this->postJson("/api/admin/intakes/{$intake->id}/convert")->assertOk();

        $animalId = $response->json('animal.id');
        $photoPath = $animalId
            ? \App\Models\AnimalPhoto::where('animal_id', $animalId)->value('photo_url')
            : null;

        $this->assertNotNull($photoPath, 'the converted animal should have a copied photo');
        Storage::assertExists($photoPath);                 // animal photo on the public/default disk
        Storage::disk('private')->assertExists($path);     // source doc untouched (copy, not move)
    }
}
