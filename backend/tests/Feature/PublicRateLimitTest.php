<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public, unauthenticated write/compute endpoints are rate-limited per IP so a bot can't
 * flood the rescue queue / storage or hammer the free AI-assistant path (audit §6/§10). The
 * throttle middleware runs before controller validation, so invalid bodies still count toward
 * the cap — these tests assert a 429 is returned once the per-minute limit is exceeded.
 */
class PublicRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_rescue_submission_is_rate_limited(): void
    {
        // 5 requests/min are allowed (empty body -> 422 validation); the 6th is throttled.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/rescue-reports', [])->assertStatus(422);
        }

        $this->postJson('/api/rescue-reports', [])->assertStatus(429);
    }

    public function test_public_assistant_chat_is_rate_limited(): void
    {
        // 20 requests/min are allowed (empty body -> 422 validation); the 21st is throttled.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/assistant/chat', [])->assertStatus(422);
        }

        $this->postJson('/api/assistant/chat', [])->assertStatus(429);
    }
}
