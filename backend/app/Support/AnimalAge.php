<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Animal ages. The shelter usually only knows roughly how old an animal is ("about 3 months",
 * "2 years"), and a puppy's age changes every week — so an animal stores an estimated birth date,
 * and its age is worked out from that whenever it's shown. Staff still enter an age; this turns
 * it into a birth date (in the shelter's day, Manila) and back into words.
 */
final class AnimalAge
{
    public const UNITS = ['weeks', 'months', 'years'];

    /** No dog or cat on record has lived past 40, so an older age is a typo. */
    public const MAX_YEARS = 40;

    private const TIMEZONE = 'Asia/Manila';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    /** The estimated birth date of an animal that is "$value $unit" old today. */
    public static function birthDate(int $value, string $unit): CarbonImmutable
    {
        $today = self::today();

        return match ($unit) {
            'weeks' => $today->subWeeks($value),
            'months' => $today->subMonthsNoOverflow($value),
            default => $today->subYearsNoOverflow($value),
        };
    }

    /** What's wrong with an entered age, or null when it's fine (or not given). */
    public static function problem(mixed $value, ?string $unit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! in_array($unit, self::UNITS, true)) {
            return 'Choose weeks, months or years for the age.';
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            return 'The age must be a whole number, e.g. 3.';
        }
        if ($unit === 'years' && (int) $value === 0) {
            return 'For an animal under a year old, give its age in months or weeks.';
        }
        if (self::birthDate((int) $value, $unit)->lt(self::today()->subYears(self::MAX_YEARS))) {
            return 'That is more than '.self::MAX_YEARS.' years. Check the age.';
        }

        return null;
    }

    /**
     * An age written as text — "3 months", "6 wks", "2 yrs", "about 2 years", "1.5 years", or a
     * plain number, which means years — as ['value' => int, 'unit' => …]. Null if it isn't one.
     * A fraction moves down a unit: 1.5 years is 18 months, 2.5 months is 11 weeks.
     */
    public static function parse(mixed $text): ?array
    {
        if (is_int($text) || is_float($text)) {
            [$number, $unit] = [(float) $text, 'years'];
        } else {
            $t = mb_strtolower(trim((string) $text));
            $t = trim((string) preg_replace('/^(about|approx\.?|approximately|around|est\.?|~)\s*/u', '', $t));
            if (! preg_match('/^(\d+(?:\.\d+)?)\s*([a-z]*)\.?(?:\s+old)?$/u', $t, $m)) {
                return null;
            }
            $number = (float) $m[1];
            $unit = match (true) {
                in_array($m[2], ['', 'y', 'yr', 'yrs', 'year', 'years'], true) => 'years',
                in_array($m[2], ['m', 'mo', 'mos', 'month', 'months'], true) => 'months',
                in_array($m[2], ['w', 'wk', 'wks', 'week', 'weeks'], true) => 'weeks',
                default => null,
            };
            if ($unit === null) {
                return null;
            }
        }

        if (floor($number) != $number) {
            return match ($unit) {
                'years' => ['value' => (int) round($number * 12), 'unit' => 'months'],
                'months' => ['value' => (int) round($number * 52 / 12), 'unit' => 'weeks'],
                default => ['value' => (int) round($number), 'unit' => 'weeks'],
            };
        }

        return ['value' => (int) $number, 'unit' => $unit];
    }

    /** Whole months old today. */
    public static function months(?CarbonInterface $birth): ?int
    {
        return $birth ? max(0, (int) floor(self::from($birth)->diffInMonths(self::today()))) : null;
    }

    /** "3 weeks" (under three months — puppies and kittens are counted in weeks), "5 months" (under a year), "1 year", "4 years"; null if unknown. */
    public static function label(?CarbonInterface $birth): ?string
    {
        if (! $birth) {
            return null;
        }
        $months = self::months($birth);
        if ($months < 3) {
            $weeks = max(0, (int) floor(self::from($birth)->diffInWeeks(self::today())));

            return $weeks < 1 ? 'Under 1 week' : self::plural($weeks, 'week');
        }

        return $months < 12 ? self::plural($months, 'month') : self::plural(intdiv($months, 12), 'year');
    }

    /** The age the way staff would type it, to fill the edit form. */
    public static function parts(?CarbonInterface $birth): ?array
    {
        if (! $birth) {
            return null;
        }
        $months = self::months($birth);

        return match (true) {
            $months < 3 => ['value' => max(0, (int) floor(self::from($birth)->diffInWeeks(self::today()))), 'unit' => 'weeks'],
            $months < 24 => ['value' => $months, 'unit' => 'months'],
            default => ['value' => intdiv($months, 12), 'unit' => 'years'],
        };
    }

    /** puppy (under 2), adult (under 7) or senior — how the care guides are tagged. */
    public static function lifeStage(?CarbonInterface $birth): ?string
    {
        $months = self::months($birth);

        return match (true) {
            $months === null => null,
            $months < 24 => 'puppy',
            $months < 84 => 'adult',
            default => 'senior',
        };
    }

    private static function from(CarbonInterface $birth): CarbonImmutable
    {
        return CarbonImmutable::parse($birth->toDateString(), self::TIMEZONE)->startOfDay();
    }

    private static function plural(int $n, string $word): string
    {
        return $n.' '.$word.($n === 1 ? '' : 's');
    }
}
