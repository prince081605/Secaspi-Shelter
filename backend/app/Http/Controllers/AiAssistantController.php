<?php

namespace App\Http\Controllers;

use App\Models\Animal;
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

    /**
     * Cheap, broad keyword FAQ — covers greetings, the main shelter topics, and live questions
     * about our available animals, all without any paid API call. Returns null only for
     * genuinely off-topic questions (the model handles those when a key is configured).
     */
    private function faqAnswer(string $message, array $settings): ?string
    {
        $m = Str::lower(trim($message));
        $has = fn (array $words) => collect($words)->contains(fn ($w) => str_contains($m, $w));

        // --- Greetings & small talk ---------------------------------------------------------
        if (preg_match('/^(hi|hello|hey|yo|good (morning|afternoon|evening)|kumusta|kamusta)\b/', $m)) {
            return "Hello! 🐾 I can help you adopt, foster, donate, volunteer, book a visit, report a stray, or find an animal that fits you. What would you like to know?";
        }
        if ($has(['thank', 'salamat'])) {
            return "You're welcome! Is there anything else I can help you with? 😊";
        }
        if ($has(['what can you do', 'what can you help', 'who are you', 'what are you', 'how can you help', 'help me'])) {
            return "I can answer questions about adopting, fostering, donating, volunteering, visiting, reporting strays, and our available animals. Try “how do I adopt?”, “do you have small dogs?”, or take our Matchmaker quiz for tailored picks.";
        }

        // --- Adoption -----------------------------------------------------------------------
        if ($has(['how do i adopt', 'how to adopt', 'adoption process', 'adopt a', 'requirements', 'requirement to adopt', 'how can i adopt', 'adoption step'])) {
            $policies = trim($settings['adoption_policies'] ?? '');

            return $policies !== ''
                ? $policies
                : 'To adopt: browse our available animals, submit an adoption application, and our team reviews it and arranges a home visit before finalizing. Start on the Adoption page — or try the Matchmaker quiz to find a good fit first.';
        }
        if (($has(['fee', 'cost', 'price', 'how much', 'magkano', 'payment']) && $has(['adopt', 'adoption', 'pet', 'dog', 'cat']))) {
            return 'Adoption involves a small processing/care fee that helps cover vaccination and basic care — our team confirms the exact amount during your application. The goal is responsible rehoming, not profit.';
        }
        if ($has(['foster'])) {
            return "Fostering means temporarily caring for an animal until it's adopted. Submit a foster request from an animal's page (or the Foster option) and we'll coordinate dates and support with you.";
        }

        // --- Donations & transparency -------------------------------------------------------
        if ($has(['where does the money', 'how are donations', 'donation used', 'where do donations', 'transparency', 'how is the money'])) {
            return 'Donations go to food, medical treatment, vaccinations, and shelter operations. You can see totals and a breakdown on our Transparency page.';
        }
        if ($has(['donate', 'donation', 'gcash', 'support you', 'give money', 'contribute'])) {
            return 'Thank you for considering a donation! 💛 You can give via GCash, cash, or bank transfer from the Donate page after logging in — every bit helps feed and treat our animals. You can also see your impact and badges on your dashboard.';
        }

        // --- Volunteer ----------------------------------------------------------------------
        if ($has(['volunteer', 'volunteering', 'help out', 'join your team', 'work with you'])) {
            return "We'd love your help! 🤝 Submit a volunteer application from the Volunteer page and our team will reach out about onboarding and tasks.";
        }

        // --- Visiting & hours ---------------------------------------------------------------
        if ($has(['visit', 'opening hour', 'open hours', 'what time', 'hours', 'schedule a visit', 'tour', 'come over', 'meet the'])) {
            return 'You can book a visit to meet our animals through the Visit page after logging in — pick a date and time slot and we\'ll confirm it.';
        }

        // --- Location & contact -------------------------------------------------------------
        if ($has(['where are you', 'where is', 'location', 'address', 'how to get', 'find you'])) {
            return ! empty($settings['address'])
                ? "We're located at {$settings['address']}. You can book a visit through the Visit page."
                : 'Please contact us for our exact location and directions, or book a visit on the Visit page.';
        }
        if ($has(['contact', 'phone', 'email', 'reach you', 'number', 'message you'])) {
            $parts = array_filter([
                ! empty($settings['contact_email']) ? "email {$settings['contact_email']}" : null,
                ! empty($settings['contact_phone']) ? "call {$settings['contact_phone']}" : null,
            ]);

            return ($parts ? 'You can ' . implode(' or ', $parts) . '.' : 'Please reach out through our contact options on the site.')
                . ' Logged-in users can also message us directly from the dashboard.';
        }

        // --- Rescue / strays ----------------------------------------------------------------
        if ($has(['lost', 'found a', 'stray', 'rescue', 'report a', 'injured', 'abandoned', 'saw a dog'])) {
            return 'If you\'ve spotted a stray or an animal in distress, please file a rescue report from our home page — add the location (you can pin it on the map) and a photo, and our rescue team will respond as fast as possible.';
        }

        // --- Health / care ------------------------------------------------------------------
        if ($has(['vaccin', 'dewor', 'health', 'sick', 'medical', 'spay', 'neuter'])) {
            return 'Our animals receive vaccinations and basic medical care, and each profile lists its health records. For specific medical questions about an animal, book a visit or contact our team.';
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
        $triggers = ['animal', 'dog', 'puppy', 'puppies', 'cat', 'kitten', 'available', 'adopt', 'looking for', 'do you have', 'show me', 'which', 'recommend', 'suit', 'good with', 'any '];
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

            return '• ' . $a->name . ' (' . implode(', ', $bits) . ')';
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
