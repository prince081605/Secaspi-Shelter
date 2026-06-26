<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_starts_a_conversation_and_staff_can_reply(): void
    {
        $member = User::factory()->create();
        $staff = User::factory()->staff()->create();

        // Member opens a conversation.
        Sanctum::actingAs($member);
        $res = $this->postJson('/api/conversations', [
            'subject' => 'Question about adoption',
            'body' => 'Is Buddy still available?',
        ])->assertCreated();
        $conversationId = $res->json('conversation.id');

        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'user_id' => $member->id]);
        // Staff got notified.
        $this->assertDatabaseHas('app_notifications', ['user_id' => $staff->id, 'type' => 'new_message']);

        // Staff sees it in the inbox and replies.
        Sanctum::actingAs($staff);
        $this->getJson('/api/admin/conversations')
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $conversationId);

        $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'Yes, Buddy is available!',
        ])->assertOk();

        // The member got notified of the reply.
        $this->assertDatabaseHas('app_notifications', ['user_id' => $member->id, 'type' => 'new_message']);

        // Member sees both messages in order.
        Sanctum::actingAs($member);
        $this->getJson("/api/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('conversation.messages.0.body', 'Is Buddy still available?')
            ->assertJsonPath('conversation.messages.1.body', 'Yes, Buddy is available!');
    }

    public function test_a_member_cannot_read_someone_elses_conversation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/conversations', ['subject' => 'Hi', 'body' => 'Private message'])
            ->json('conversation.id');

        Sanctum::actingAs($other);
        $this->getJson("/api/conversations/{$id}")->assertStatus(403);
    }

    public function test_non_staff_cannot_open_the_inbox(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/conversations')->assertStatus(403);
    }
}
