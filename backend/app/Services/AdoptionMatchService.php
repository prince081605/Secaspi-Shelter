<?php

namespace App\Services;

use App\Models\Animal;
use Illuminate\Support\Str;

/**
 * Rule-based adoption matchmaker. Scores an animal against an adopter's lifestyle answers and
 * returns an explainable result (percent + the reasons behind it). Deliberately transparent —
 * every adjustment maps to a human-readable reason, which is what makes it demo/defence friendly.
 *
 * Behavioural traits come from the animal's behavioral_assessment array (same vocabulary the
 * care guides use), e.g. "excessive energy", "dog-to-dog aggression", "extreme shyness".
 */
class AdoptionMatchService
{
    /** Trait groups (substring-matched, case-insensitive) and what they imply. */
    private const HIGH_ENERGY = ['excessive energy', 'high energy'];
    private const CHALLENGING = ['aggression', 'resource guarding', 'post-trauma', 'trust issues', 'separation anxiety'];
    private const NOT_WITH_KIDS = ['food aggression', 'resource guarding', 'territorial aggression', 'dog-to-dog aggression', 'aggression & resource guarding'];
    private const NOT_WITH_PETS = ['dog-to-dog aggression', 'territorial aggression', 'resource guarding'];
    private const SHY_ANXIOUS = ['extreme shyness', 'fear of loud noises', 'separation anxiety', 'post-trauma', 'trust issues'];
    private const NOISY = ['excessive barking'];

    /**
     * @param  array<string,string>  $answers  home_type, activity_level, experience, household, preferred_species, preferred_size
     * @return array{percent:int, reasons:array<string>, cautions:array<string>}
     */
    public function score(Animal $animal, array $answers): array
    {
        $traits = array_map('mb_strtolower', (array) ($animal->behavioral_assessment ?? []));
        $reasons = [];
        $cautions = [];
        $score = 70; // neutral baseline; adjusted up for good fits, down for mismatches

        $has = fn (array $needles) => $this->traitsMatch($traits, $needles);

        // --- Species preference -------------------------------------------------------------
        $prefSpecies = $answers['preferred_species'] ?? 'any';
        if ($prefSpecies !== 'any') {
            if (Str::lower($animal->species ?? '') === Str::lower($prefSpecies)) {
                $score += 12;
                $reasons[] = "Is a {$animal->species}, which is what you're looking for";
            } else {
                $score -= 30;
                $cautions[] = "Is a {$animal->species}, not the {$prefSpecies} you preferred";
            }
        }

        // --- Home type vs size --------------------------------------------------------------
        $home = $answers['home_type'] ?? '';
        $size = Str::lower($animal->size ?? '');
        if ($home === 'apartment') {
            if (in_array($size, ['small', 'medium'], true)) {
                $score += 8;
                $reasons[] = "{$animal->size} size suits apartment living";
            } elseif ($size === 'large') {
                $score -= 12;
                $cautions[] = 'A large animal may find an apartment cramped';
            }
        } elseif ($home === 'house_with_yard' && $size === 'large') {
            $score += 8;
            $reasons[] = 'A yard gives a large animal room to roam';
        }

        // --- Activity level vs energy -------------------------------------------------------
        $activity = $answers['activity_level'] ?? 'moderate';
        if ($has(self::HIGH_ENERGY)) {
            if ($activity === 'high') {
                $score += 12;
                $reasons[] = 'High energy — a great fit for your active lifestyle';
            } elseif ($activity === 'low') {
                $score -= 14;
                $cautions[] = 'High energy — needs more exercise than a quiet home offers';
            }
        } elseif ($activity === 'low' && ! $has(self::HIGH_ENERGY)) {
            $score += 5;
            $reasons[] = 'Calm enough for a relaxed household';
        }

        // --- Experience vs challenging behaviours -------------------------------------------
        $experience = $answers['experience'] ?? 'some';
        if ($has(self::CHALLENGING)) {
            if ($experience === 'experienced') {
                $score += 8;
                $reasons[] = 'Has behaviours best handled by an experienced owner — like you';
            } elseif ($experience === 'first_time') {
                $score -= 16;
                $cautions[] = 'Has behaviours that need an experienced owner; tricky for a first-timer';
            }
        }
        if ($has(self::SHY_ANXIOUS) && $experience === 'experienced') {
            $score += 4;
            $reasons[] = 'Shy/anxious — your patience would help it settle';
        }

        // --- Household: kids & other pets ---------------------------------------------------
        $household = $answers['household'] ?? '';
        if ($household === 'kids' && $has(self::NOT_WITH_KIDS)) {
            $score -= 18;
            $cautions[] = 'May do better in a home without young children';
        }
        if ($household === 'other_pets' && $has(self::NOT_WITH_PETS)) {
            $score -= 16;
            $cautions[] = 'May struggle to share a home with other pets';
        }
        if ($household === 'quiet_adults_only' && ($has(self::SHY_ANXIOUS) || $has(self::NOISY))) {
            $score += 6;
            $reasons[] = 'A calm adults-only home suits its temperament';
        }

        if (empty($traits)) {
            $reasons[] = 'No behavioural concerns on record — an easygoing match';
        }

        $percent = max(5, min(99, $score));

        return [
            'percent' => (int) $percent,
            'reasons' => array_values(array_unique($reasons)),
            'cautions' => array_values(array_unique($cautions)),
        ];
    }

    private function traitsMatch(array $traits, array $needles): bool
    {
        foreach ($traits as $trait) {
            foreach ($needles as $needle) {
                if (str_contains($trait, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
