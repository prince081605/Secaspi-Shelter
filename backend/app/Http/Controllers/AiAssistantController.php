<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Setting;
use App\Services\AiAssistantService;
use App\Services\FaqMatcher;
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
    // Shelter-wide ceiling on paid AI replies per day, on top of the per-visitor cap — bounds total
    // spend even if many visitors (or an IP-rotating bot) each hit their own cap. Tunable via the
    // optional `ai_daily_global_cap` setting.
    private const GLOBAL_DAILY_CAP = 500;

    public function chat(Request $request, AiAssistantService $assistant, FaqMatcher $matcher)
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
        if ($faq = $this->faqAnswer($message, $settings, $matcher)) {
            return response()->json(['reply' => $faq, 'source' => 'faq']);
        }

        // 2. AI only when enabled + configured.
        $enabled = ($settings['ai_assistant_enabled'] ?? '0') === '1';
        if (! $enabled || ! $assistant->isConfigured()) {
            return response()->json(['reply' => $this->fallback($settings), 'source' => 'disabled']);
        }

        // 3. Daily caps (count only paid attempts): per-visitor, and a shelter-wide global ceiling.
        $cap = (int) ($settings['ai_daily_message_cap'] ?? 20) ?: 20;
        $globalCap = (int) ($settings['ai_daily_global_cap'] ?? self::GLOBAL_DAILY_CAP) ?: self::GLOBAL_DAILY_CAP;
        $today = now()->toDateString();
        $counterKey = 'ai_count:'.$request->ip().':'.$today;
        $globalKey = 'ai_count_global:'.$today;

        if ((int) Cache::get($counterKey, 0) >= $cap || (int) Cache::get($globalKey, 0) >= $globalCap) {
            return response()->json([
                'reply' => $this->fallback($settings)." (I've reached today's question limit — please try again tomorrow.)",
                'source' => 'limit',
            ]);
        }

        // 4. Cache identical questions; otherwise ask the model.
        $cacheKey = 'ai_reply:'.md5(Str::lower(trim($message)));
        $reply = Cache::get($cacheKey);

        if ($reply === null) {
            $reply = $assistant->reply($message, (array) $request->input('history', []));
            Cache::put($counterKey, (int) Cache::get($counterKey, 0) + 1, now()->endOfDay());
            Cache::put($globalKey, (int) Cache::get($globalKey, 0) + 1, now()->endOfDay());
            if ($reply) {
                Cache::put($cacheKey, $reply, now()->addHour());
            }
        }

        if (! $reply) {
            return response()->json(['reply' => $this->fallback($settings), 'source' => 'faq']);
        }

        return response()->json(['reply' => $reply, 'source' => 'ai']);
    }

    /**
     * Cheap, broad keyword FAQ — covers greetings, the main shelter topics, and live questions
     * about our available animals, all without any paid API call. Returns null only for
     * genuinely off-topic questions (the model handles those when a key is configured).
     */
    private function faqAnswer(string $message, array $settings, FaqMatcher $matcher): ?string
    {
        $m = Str::lower(trim($message));
        $has = fn (array $words) => collect($words)->contains(fn ($w) => str_contains($m, $w));

        // --- Greetings & small talk (kept inline) -------------------------------------------
        if (preg_match('/^(hi|hello|hey|yo|good (morning|afternoon|evening)|kumusta|kamusta)\b/', $m)) {
            return 'Hello! 🐾 I can help you adopt, foster, donate, volunteer, book a visit, report a stray, or find an animal that fits you. What would you like to know?';
        }
        if ($has(['thank', 'salamat'])) {
            return "You're welcome! Is there anything else I can help you with? 😊";
        }
        if ($has(['what can you do', 'what can you help', 'who are you', 'what are you', 'how can you help'])) {
            return 'I can answer questions about adopting, fostering, donating, volunteering, visiting, reporting strays, and our available animals. Try “how do I adopt?”, “do you have small dogs?”, or take our Matchmaker quiz for tailored picks.';
        }

        // --- Trainable knowledge base (admin-curated, similarity-matched) -------------------
        if ($entry = $matcher->match($message)) {
            $entry->increment('hits');

            return $entry->answer;
        }

        // --- Live animal availability (answered from real data) -----------------------------
        if ($answer = $this->animalAnswer($m)) {
            return $answer;
        }

        return null;
    }

    /** Answer "do you have…/which…/show me…" style questions from currently-available animals. */
    private function animalAnswer(string $m): ?string
    {
        // Require a clear "list/find an animal" intent — not just the presence of "dog"/"puppy",
        // so questions like "I saw a hurt puppy" or "can I see the dogs" fall through to the
        // knowledge base (rescue / visit) instead of being answered with an availability list.
        $triggers = ['available', 'do you have', 'show me', 'looking for', 'find me', 'which', 'recommend', 'suit', 'good with', 'any ', 'what dogs', 'what cats', 'small dog', 'big dog', 'large dog', 'small cat', 'adoptable'];
        if (! collect($triggers)->contains(fn ($t) => str_contains($m, $t))) {
            return null;
        }

        $query = Animal::where('status', 'available');

        if (str_contains($m, 'cat') || str_contains($m, 'kitten')) {
            $query->where('species', 'like', '%cat%');
        } elseif (str_contains($m, 'dog') || str_contains($m, 'puppy') || str_contains($m, 'puppies')) {
            $query->where('species', 'like', '%dog%');
        }
        foreach (['small', 'medium', 'large'] as $size) {
            if (str_contains($m, $size)) {
                $query->where('size', $size);
            }
        }

        $animals = $query->take(25)->get();

        // Steer away from challenging temperaments when a calm/family/apartment fit is asked for.
        if (str_contains($m, 'kid') || str_contains($m, 'child') || str_contains($m, 'family') || str_contains($m, 'apartment') || str_contains($m, 'calm') || str_contains($m, 'first')) {
            $avoid = ['aggress', 'guarding', 'excessive energy', 'territorial', 'high energy'];
            $animals = $animals->filter(function (Animal $a) use ($avoid) {
                $traits = mb_strtolower(implode(' ', (array) ($a->behavioral_assessment ?? [])));
                foreach ($avoid as $bad) {
                    if (str_contains($traits, $bad)) {
                        return false;
                    }
                }

                return true;
            });
        }

        if ($animals->isEmpty()) {
            return "I couldn't find an available match for that right now. You can browse everyone on our Adoption page, or take the Matchmaker quiz for tailored suggestions.";
        }

        $list = $animals->take(5)->map(function (Animal $a) {
            $bits = array_filter([$a->species, $a->age ? "age {$a->age}" : null, $a->size]);

            return '• '.$a->name.' ('.implode(', ', $bits).')';
        })->implode("\n");
        $more = $animals->count() > 5 ? "\n…and more on the Adoption page." : '';

        return "Here are some animals you can meet:\n{$list}{$more}\nWant tailored picks? Try our Matchmaker quiz. 🐾";
    }

    private function fallback(array $settings): string
    {
        $contact = ! empty($settings['contact_email']) ? " or email {$settings['contact_email']}" : '';

        return "I'm not sure about that one — but I can help with adopting or fostering, donating, volunteering, visiting, reporting a stray, or finding an animal that fits you. You can also try our Matchmaker quiz, browse the Adoption page{$contact}. What would you like?";
    }
}
