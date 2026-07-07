<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for the donation expense categories, their per-category monthly goals,
 * and the "spillover" allocation that powers the public Transparency board.
 *
 * A donor picks one of these categories when giving (or "where it's needed most" = null). The
 * category goals default to the shelter's monthly budget breakdown but are admin-editable via the
 * `cat_goal_*` settings — the defaults below happen to sum to the overall monthly goal
 * (PublicHomeController::DEFAULT_MONTHLY_GOAL = 80,220).
 */
class DonationCategories
{
    /**
     * Ordered map: key => [label, default monthly goal in pesos]. Order also drives the display
     * order on the Transparency board.
     */
    public const DEFAULTS = [
        'personnel'      => ['label' => 'Personnel Expenses',            'goal' => 17760],
        'facility'       => ['label' => 'Facility / Shelter Expenses',   'goal' => 21700],
        'transportation' => ['label' => 'Transportation Expenses',       'goal' => 720],
        'animal_care'    => ['label' => 'Animal Care / Food Expenses',   'goal' => 35500],
        'cleaning'       => ['label' => 'Cleaning & Sanitation Supplies', 'goal' => 4540],
    ];

    /** @return list<string> The valid category keys, in display order. */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** @return array<string, string> key => human label. */
    public static function labels(): array
    {
        return array_map(fn ($c) => $c['label'], self::DEFAULTS);
    }

    /** The settings key that overrides a category's default goal (e.g. cat_goal_personnel). */
    public static function goalSettingKey(string $categoryKey): string
    {
        return 'cat_goal_'.$categoryKey;
    }

    /**
     * Effective per-category goals: the admin-set `cat_goal_*` settings layered over the defaults.
     *
     * @return array<string, float> key => goal
     */
    public static function goals(): array
    {
        $overrides = DB::table('settings')
            ->whereIn('key', array_map([self::class, 'goalSettingKey'], self::keys()))
            ->pluck('value', 'key');

        $goals = [];
        foreach (self::DEFAULTS as $key => $meta) {
            $setting = $overrides[self::goalSettingKey($key)] ?? null;
            $goals[$key] = is_numeric($setting) ? (float) $setting : (float) $meta['goal'];
        }

        return $goals;
    }

    /**
     * Allocate donations across categories with spillover.
     *
     * Each category is filled up to its goal from its own directly-attributed donations; any surplus
     * (a category donated past its goal) plus the "needed most" pool is redistributed to the
     * still-underfunded categories, neediest-first. Whatever is left over once every goal is met is
     * reported as `surplus` (a reserve).
     *
     * @param  array<string, float>  $direct     key => amount donated directly to that category
     * @param  float                 $needyPool  amount donated to "where it's needed most" (null category)
     * @param  array<string, float>  $goals      key => goal (defaults to self::goals())
     * @return array{
     *     categories: list<array{key:string,label:string,goal:float,direct:float,allocated:float,progress_pct:int,funded:bool}>,
     *     redistributed_total: float,
     *     surplus: float
     * }
     */
    public static function allocate(array $direct, float $needyPool = 0.0, ?array $goals = null): array
    {
        $goals = $goals ?? self::goals();

        $allocated = [];
        $pool = max(0.0, $needyPool);

        // 1) Cap each category at its goal from its own donations; anything above spills into the pool.
        foreach (self::keys() as $key) {
            $d = max(0.0, (float) ($direct[$key] ?? 0));
            $goal = (float) ($goals[$key] ?? 0);
            $allocated[$key] = min($d, $goal);
            if ($d > $goal) {
                $pool += $d - $goal;
            }
        }

        // 2) Redistribute the pool to the underfunded categories, largest remaining gap first.
        $redistributed = 0.0;
        while ($pool > 0.0001) {
            $neediestKey = null;
            $largestGap = 0.0;
            foreach (self::keys() as $key) {
                $gap = (float) ($goals[$key] ?? 0) - $allocated[$key];
                if ($gap > $largestGap) {
                    $largestGap = $gap;
                    $neediestKey = $key;
                }
            }

            if ($neediestKey === null) {
                break; // every category is fully funded
            }

            $take = min($largestGap, $pool);
            $allocated[$neediestKey] += $take;
            $pool -= $take;
            $redistributed += $take;
        }

        $categories = [];
        foreach (self::DEFAULTS as $key => $meta) {
            $goal = (float) ($goals[$key] ?? 0);
            $alloc = $allocated[$key];
            $categories[] = [
                'key'          => $key,
                'label'        => $meta['label'],
                'goal'         => $goal,
                'direct'       => max(0.0, (float) ($direct[$key] ?? 0)),
                'allocated'    => round($alloc, 2),
                'progress_pct' => $goal > 0 ? (int) min(100, round(($alloc / $goal) * 100)) : 100,
                'funded'       => $alloc >= $goal,
            ];
        }

        return [
            'categories'          => $categories,
            'redistributed_total' => round($redistributed, 2),
            'surplus'             => round(max(0.0, $pool), 2),
        ];
    }
}
