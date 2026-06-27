<?php

namespace Tests\Feature;

use App\Models\FaqEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaqControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_crud_faq_entries(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $id = $this->postJson('/api/admin/faqs', [
            'question' => 'Do you have puppies?',
            'answer' => 'Yes, check the Adoption page.',
            'tags' => 'puppy young dog',
        ])->assertCreated()->json('faq.id');

        $this->getJson('/api/admin/faqs')->assertOk()->assertJsonFragment(['id' => $id]);

        $this->putJson("/api/admin/faqs/{$id}", [
            'question' => 'Do you have puppies?',
            'answer' => 'Updated answer.',
        ])->assertOk()->assertJsonPath('faq.answer', 'Updated answer.');

        $this->deleteJson("/api/admin/faqs/{$id}")->assertOk();
        $this->assertDatabaseMissing('faq_entries', ['id' => $id]);
    }

    public function test_staff_cannot_manage_faqs(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());
        $this->getJson('/api/admin/faqs')->assertStatus(403);
        $this->postJson('/api/admin/faqs', ['question' => 'x', 'answer' => 'y'])->assertStatus(403);
    }

    public function test_validation_rejects_empty_entry(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/admin/faqs', [])->assertStatus(422);
    }
}
