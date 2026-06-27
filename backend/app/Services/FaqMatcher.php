<?php

namespace App\Services;

use App\Models\FaqEntry;
use Illuminate\Support\Facades\Cache;

/**
 * Free, in-app similarity matcher for the trainable FAQ knowledge base. Builds a TF-IDF model
 * over each enabled entry's (question + tags) and returns the best entry for an incoming question
 * by cosine similarity — so paraphrases match without needing exact keywords. No external API.
 *
 * The model is cached and keyed on a signature (enabled count + latest updated_at), so admin edits
 * take effect immediately while we avoid rebuilding the vectors on every request.
 */
class FaqMatcher
{
    /** Minimum cosine similarity to accept a match. Tuned for short shelter questions. */
    private const THRESHOLD = 0.28;

    private const STOPWORDS = [
        'the', 'a', 'an', 'is', 'are', 'do', 'does', 'how', 'to', 'i', 'you', 'we', 'my', 'me',
        'can', 'of', 'for', 'and', 'or', 'in', 'on', 'at', 'it', 'this', 'that', 'with', 'your',
        'be', 'will', 'would', 'should', 'what', 'where', 'when', 'who', 'please', 'tell', 'about',
        'get', 'got', 'have', 'has', 'want', 'need', 'there', 'here', 'so', 'if', 'any', 'some',
    ];

    public function match(string $query): ?FaqEntry
    {
        $model = $this->model();
        if (empty($model['docs'])) {
            return null;
        }

        $qVec = $this->normalize($this->vector($this->tokenize($query), $model['idf']));
        if (empty($qVec)) {
            return null;
        }

        $bestId = null;
        $bestScore = 0.0;
        foreach ($model['docs'] as $doc) {
            $score = $this->cosine($qVec, $doc['vec']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $doc['id'];
            }
        }

        return ($bestId && $bestScore >= self::THRESHOLD) ? FaqEntry::find($bestId) : null;
    }

    /** Build (and cache) the TF-IDF vectors for all enabled entries. */
    private function model(): array
    {
        $signature = FaqEntry::where('enabled', true)->count()
            . ':' . (FaqEntry::where('enabled', true)->max('updated_at') ?? '0');

        return Cache::remember('faq_model:' . md5($signature), now()->addHours(6), function () {
            $entries = FaqEntry::where('enabled', true)->get(['id', 'question', 'tags']);

            $tokensById = [];
            $df = [];
            foreach ($entries as $e) {
                $tokens = $this->tokenize($e->question . ' ' . ($e->tags ?? ''));
                $tokensById[$e->id] = $tokens;
                foreach (array_unique($tokens) as $t) {
                    $df[$t] = ($df[$t] ?? 0) + 1;
                }
            }

            $n = max(1, $entries->count());
            $idf = [];
            foreach ($df as $term => $count) {
                $idf[$term] = log(($n + 1) / ($count + 1)) + 1;
            }

            $docs = [];
            foreach ($tokensById as $id => $tokens) {
                $docs[] = ['id' => $id, 'vec' => $this->normalize($this->vector($tokens, $idf))];
            }

            return ['idf' => $idf, 'docs' => $docs];
        });
    }

    private function tokenize(string $text): array
    {
        $text = preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($text));

        return array_values(array_filter(
            explode(' ', $text),
            fn ($t) => strlen($t) >= 2 && ! in_array($t, self::STOPWORDS, true),
        ));
    }

    /** TF-IDF vector limited to known vocabulary (unknown query terms are ignored). */
    private function vector(array $tokens, array $idf): array
    {
        $tf = [];
        foreach ($tokens as $t) {
            if (isset($idf[$t])) {
                $tf[$t] = ($tf[$t] ?? 0) + 1;
            }
        }

        $vec = [];
        foreach ($tf as $term => $count) {
            $vec[$term] = $count * $idf[$term];
        }

        return $vec;
    }

    private function normalize(array $vec): array
    {
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vec)));
        if ($norm == 0.0) {
            return $vec;
        }

        return array_map(fn ($v) => $v / $norm, $vec);
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $term => $weight) {
            if (isset($b[$term])) {
                $dot += $weight * $b[$term];
            }
        }

        return $dot;
    }
}
