<?php

namespace Tests\Feature;

use App\Models\FaqEntry;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_faq_questions_are_answered_without_calling_the_api(): void
    {
        Http::fake(); // any outbound call would be recorded — we assert none happen

        FaqEntry::create([
            'question' => 'How do I adopt a dog?',
            'answer' => 'Submit an adoption application and we will review it.',
            'tags' => 'adoption process apply',
            'enabled' => true,
        ]);

        $this->postJson('/api/assistant/chat', ['message' => 'How do I adopt a dog?'])
            ->assertOk()
            ->assertJsonPath('source', 'faq');

        Http::assertNothingSent();
    }

    public function test_with_ai_off_a_novel_question_falls_back_and_makes_no_call(): void
    {
        Http::fake();
        Setting::setMany(['ai_assistant_enabled' => '0']);

        $this->postJson('/api/assistant/chat', ['message' => 'Tell me a fun fact about the universe'])
            ->assertOk()
            ->assertJsonPath('source', 'disabled');

        Http::assertNothingSent();
    }

    public function test_when_enabled_and_configured_a_novel_question_uses_the_model(): void
    {
        config(['services.openai.key' => 'test-key']);
        Setting::setMany(['ai_assistant_enabled' => '1']);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Buddy would be a great pick for you!']]],
            ], 200),
        ]);

        // An off-topic question (animal/shelter FAQs are now answered for free) reaches the model.
        $this->postJson('/api/assistant/chat', ['message' => 'Tell me about general pet nutrition advice'])
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('reply', 'Buddy would be a great pick for you!');
    }

    public function test_global_daily_cap_stops_paid_calls_even_for_a_fresh_visitor(): void
    {
        config(['services.openai.key' => 'test-key']);
        Setting::setMany(['ai_assistant_enabled' => '1', 'ai_daily_global_cap' => '2']);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'should never be sent']]],
            ], 200),
        ]);

        // Shelter-wide counter already at the global cap; this visitor's own counter is 0.
        Cache::put('ai_count_global:'.now()->toDateString(), 2, now()->endOfDay());

        $this->postJson('/api/assistant/chat', ['message' => 'Tell me about general pet nutrition advice'])
            ->assertOk()
            ->assertJsonPath('source', 'limit');

        Http::assertNothingSent();
    }

    public function test_it_validates_the_message(): void
    {
        $this->postJson('/api/assistant/chat', [])->assertStatus(422);
    }
}
