<?php

namespace Tests\Feature;

use App\Models\FaqEntry;
use App\Services\FaqMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqMatcherTest extends TestCase
{
    use RefreshDatabase;

    private function matcher(): FaqMatcher
    {
        return app(FaqMatcher::class);
    }

    public function test_a_paraphrase_matches_the_right_entry(): void
    {
        FaqEntry::create([
            'question' => 'How do I adopt a dog?',
            'answer' => 'Submit an adoption application and we will review it.',
            'tags' => 'adoption process apply requirements',
            'enabled' => true,
        ]);
        FaqEntry::create([
            'question' => 'How can I donate?',
            'answer' => 'You can donate via GCash.',
            'tags' => 'donation money gcash support',
            'enabled' => true,
        ]);

        $hit = $this->matcher()->match("what's the process to adopt a pet");

        $this->assertNotNull($hit);
        $this->assertStringContainsString('application', $hit->answer);
    }

    public function test_an_unrelated_question_returns_null(): void
    {
        FaqEntry::create(['question' => 'How do I adopt a dog?', 'answer' => 'x', 'tags' => 'adoption', 'enabled' => true]);

        $this->assertNull($this->matcher()->match('what is the capital of france'));
    }

    public function test_disabled_entries_are_never_matched(): void
    {
        FaqEntry::create(['question' => 'How do I adopt a dog?', 'answer' => 'x', 'tags' => 'adoption process apply', 'enabled' => false]);

        $this->assertNull($this->matcher()->match('how do i adopt a dog'));
    }
}
