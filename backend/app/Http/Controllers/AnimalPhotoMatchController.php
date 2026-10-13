<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Bulk photo update, step one: match photo file names to animals before anything is uploaded.
 * The page sends only the names; each photo is then added through the normal
 * POST /animals/{animal}/photos, one at a time, so a large batch never travels in one request.
 *
 * A file is matched by the animal's ID ("42.jpg", "42-brownie.jpg", "#42.jpg") or by its name
 * ("Brownie.jpg"; "Brownie-2.jpg", "Brownie (2).jpg" and "brownie_2.jpg" are more photos of
 * Brownie). Two animals with the same name can't be told apart, so those files are left for the
 * admin to choose.
 */
class AnimalPhotoMatchController extends Controller
{
    public const MAX_FILES = 300;

    public function match(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['required', 'string', 'max:255'],
        ], [
            'files.max' => 'Choose up to '.self::MAX_FILES.' photos at a time.',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $animals = Animal::query()->withCount('photos')->orderBy('name')->get(['id', 'name', 'species', 'status']);
        $byId = $animals->keyBy('id');
        $byName = $animals->groupBy(fn (Animal $a) => self::normalise($a->name));

        $matches = collect($validator->validated()['files'])->map(function (string $file) use ($byId, $byName) {
            return ['file' => $file, ...$this->matchOne($file, $byId, $byName)];
        });

        return response()->json([
            'matches' => $matches->values(),
            // Every animal, for picking one by hand when a file didn't match.
            'animals' => $animals->map(fn (Animal $a) => $this->summary($a))->values(),
        ]);
    }

    private function matchOne(string $file, Collection $byId, Collection $byName): array
    {
        $base = self::normalise(pathinfo($file, PATHINFO_FILENAME));

        // An ID first: "42", "#42", "42-brownie", "42 brownie".
        if (preg_match('/^#?(\d+)(?:[ ]|$)/', $base, $m) && $byId->has((int) $m[1])) {
            return ['how' => 'id', 'animal' => $this->summary($byId[(int) $m[1]]), 'candidates' => []];
        }

        // Then the name, as written, or without a trailing photo number ("brownie 2", "brownie (2)").
        $names = array_unique([$base, trim((string) preg_replace('/\s*\(?\d+\)?$/', '', $base))]);
        foreach ($names as $name) {
            $found = $name === '' ? null : $byName->get($name);
            if (! $found) {
                continue;
            }
            if ($found->count() === 1) {
                return ['how' => 'name', 'animal' => $this->summary($found->first()), 'candidates' => []];
            }

            return [
                'how' => 'ambiguous',
                'animal' => null,
                'candidates' => $found->map(fn (Animal $a) => $this->summary($a))->values()->all(),
            ];
        }

        return ['how' => 'none', 'animal' => null, 'candidates' => []];
    }

    private function summary(Animal $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'species' => $a->species,
            'status' => $a->status,
            'photos' => (int) $a->photos_count,
        ];
    }

    /** "Brownie_2" → "brownie 2": case, underscores, dashes and dots don't matter. */
    private static function normalise(?string $text): string
    {
        return trim((string) preg_replace('/[\s_.\-]+/u', ' ', mb_strtolower((string) $text)));
    }
}
