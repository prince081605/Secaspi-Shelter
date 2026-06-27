<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AiAssistantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Public chat endpoint for the AI Shelter Assistant, built to be cheap:
 *   1. Common questions are answered for free from the FAQ (no API call).
 *   2. Novel questions hit the paid model only when it's enabled + a key is set, and only
 *      within a per-visitor daily cap; identical questions are cached.
 *   3. With AI off (or no key), it still answers from the FAQ — so it works at $0.
 * Always returns { reply, source } where source is faq | ai | limit | disabled.
 */
class AiAssistantController extends Controller
{
    public function chat(Request $request, AiAssistantService $assistant)
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:500'],
            'history' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $message = $request->input('message');
        $settings = Setting::getAll();

        // 1. FAQ-first — free, instant answers for the common stuff.
        if ($faq = $this->faqAnswer($message, $settings)) {
            return response()->json(['reply' => $faq, 'source' => 'faq']);
        }

        // 2. AI only when enabled + configured.
        $enabled = ($settings['ai_assistant_enabled'] ?? '0') === '1';
        if (! $enabled || ! $assistant->isConfigured()) {
            return response()->json(['reply' => $this->fallback($settings), 'source' => 'disabled']);
        }

        // 3. Per-visitor daily cap (counts only paid attempts).
        $cap = (int) ($settings['ai_daily_message_cap'] ?? 20) ?: 20;
        $counterKey = 'ai_count:' . $request->ip() . ':' . now()->toDateString();
        if ((int) Cache::get($counterKey, 0) >= $cap) {
            return response()->json([
                'reply' => $this->fallback($settings) . " (I've reached today's question limit — please try again tomorrow.)",
                'source' => 'limit',
            ]);
        }

        // 4. Cache identical questions; otherwise ask the model.
        $cacheKey = 'ai_reply:' . md5(Str::lower(trim($message)));
        $reply = Cache::get($cacheKey);

        if ($reply === null) {
            $reply = $assistant->reply($message, (array) $request->input('history', []));
            Cache::put($counterKey, (int) Cache::get($counterKey, 0) + 1, now()->endOfDay());
            if ($reply) {
                Cache::put($cacheKey, $reply, now()->addHour());
            }
        }

        if (! $reply) {
            return response()->json(['reply' => $this->fallback($settings), 'source' => 'faq']);
        }

        return response()->json(['reply' => $reply, 'source' => 'ai']);
    }

    /** Cheap keyword FAQ. Returns null for recommendation-style or novel questions (AI handles those). */
    private function faqAnswer(string $message, array $settings): ?string
    {
        $m = Str::lower($message);
        $has = fn (array $words) => collect($words)->contains(fn ($w) => str_contains($m, $w));

        // Don't FAQ-hijack "which/recommend/suits me" questions — those want a real suggestion.
        $isRecommendation = $has(['which', 'recommend', 'suit', 'best for', 'good with', 'match', 'apartment']);

        if (! $isRecommendation && $has(['how do i adopt', 'adoption process', 'how to adopt', 'requirements to adopt', 'adopt a'])) {
            $policies = trim($settings['adoption_policies'] ?? '');

            return $policies !== ''
                ? $policies
                : 'To adopt, browse our available animals, submit an adoption application, and our team will review it and arrange a home visit before finalizing. Start at the Adoption page.';
        }

        if ($has(['donate', 'donation', 'gcash'])) {
            return 'Thank you for considering a donation! You can donate via GCash, cash, or bank transfer from the Donate page after logging in — every bit helps feed and treat our animals.';
        }

        if ($has(['volunteer', 'volunteering'])) {
            return 'We\'d love your help! Submit a volunteer application from the Volunteer page and our team will reach out about onboarding and tasks.';
        }

        if ($has(['visit', 'opening hour', 'open hours', 'hours', 'schedule a visit'])) {
            return 'You can book a visit to meet our animals through the Visit page after logging in. Pick a date and time slot and we\'ll confirm it.';
        }

        if ($has(['where', 'location', 'address'])) {
            return ! empty($settings['address'])
                ? "We're located at {$settings['address']}."
                : 'Please contact us for our exact location and directions.';
        }

        if ($has(['contact', 'phone', 'email', 'reach you'])) {
            $parts = array_filter([
                ! empty($settings['contact_email']) ? "email {$settings['contact_email']}" : null,
                ! empty($settings['contact_phone']) ? "call {$settings['contact_phone']}" : null,
            ]);

            return $parts ? 'You can ' . implode(' or ', $parts) . '.' : 'Please reach out through our contact options on the site.';
        }

        return null;
    }

    private function fallback(array $settings): string
    {
        $contact = ! empty($settings['contact_email']) ? " or email {$settings['contact_email']}" : '';

        return "I can help with adoption, donations, visiting, and volunteering. Try asking about those, browse our Adoption page{$contact}.";
    }
}
