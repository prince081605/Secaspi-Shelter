<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Talks to an OpenAI-compatible Chat Completions API for the shelter assistant. The prompt is
 * grounded in the shelter's real settings and currently-available animals so answers are
 * accurate and can recommend actual adoptable pets. Returns null when no key is configured or
 * the call fails, so the controller can fall back to the free FAQ answers.
 */
class AiAssistantService
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.openai.key'));
    }

    public function reply(string $message, array $history = []): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt()]],
            $this->trimHistory($history),
            [['role' => 'user', 'content' => Str::limit($message, 500, '')]],
        );

        try {
            $res = Http::withToken(config('services.openai.key'))
                ->timeout(20)
                ->post(rtrim(config('services.openai.base_url'), '/') . '/chat/completions', [
                    'model' => config('services.openai.model'),
                    'max_tokens' => config('services.openai.max_tokens'),
                    'temperature' => 0.4,
                    'messages' => $messages,
                ]);

            if (! $res->successful()) {
                return null;
            }

            $text = $res->json('choices.0.message.content');

            return $text ? trim($text) : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function systemPrompt(): string
    {
        $s = Setting::getAll();
        $name = $s['shelter_name'] ?? 'SECASPI Shelter';
        $persona = ($s['ai_persona'] ?? '') ?: 'a warm, concise, and helpful shelter assistant';

        $facts = array_filter([
            'Email: ' . ($s['contact_email'] ?? ''),
            'Phone: ' . ($s['contact_phone'] ?? ''),
            'Address: ' . ($s['address'] ?? ''),
            'Adoption policies: ' . Str::limit($s['adoption_policies'] ?? '', 400),
        ], fn ($line) => ! Str::endsWith($line, [': ', ':']));

        return implode("\n", [
            "You are {$persona} for {$name}, an animal shelter.",
            'Answer ONLY questions about this shelter: adoption, fostering, the animals, donations, visiting, and volunteering.',
            'Be concise (2-4 sentences). Never invent animals or facts; only use the data below.',
            'When asked what pet suits someone, recommend from the AVAILABLE ANIMALS list by name and say why briefly.',
            "If you don't know, suggest contacting the shelter.",
            '',
            'SHELTER INFO:',
            implode("\n", $facts),
            '',
            'AVAILABLE ANIMALS:',
            $this->availableAnimalsText(),
        ]);
    }

    private function availableAnimalsText(): string
    {
        $animals = Animal::where('status', 'available')->take(25)->get();

        if ($animals->isEmpty()) {
            return '(none currently listed as available)';
        }

        return $animals->map(function (Animal $a) {
            $traits = collect((array) ($a->behavioral_assessment ?? []))->take(3)->implode(', ');
            $bits = array_filter([$a->species, $a->breed, $a->age ? "age {$a->age}" : null, $a->size]);

            return "- {$a->name} (" . implode(', ', $bits) . ')' . ($traits ? " — traits: {$traits}" : '');
        })->implode("\n");
    }

    /** Keep only the last few turns to bound token cost. */
    private function trimHistory(array $history): array
    {
        $clean = [];
        foreach (array_slice($history, -6) as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = (string) ($turn['content'] ?? '');
            if ($content !== '') {
                $clean[] = ['role' => $role, 'content' => Str::limit($content, 500, '')];
            }
        }

        return $clean;
    }
}
