<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\PublicStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gamified "impact" view: turns a member's donations and volunteer hours into friendly stats
 * and earned badges, plus public leaderboards. All derived from existing data — no new tables.
 */
class ImpactController extends Controller
{
    private const COST_PER_MEAL_DEFAULT = 25.0;

    public function me(Request $request)
    {
        $user = $request->user();

        $donated = (float) DB::table('donations')
            ->where('user_id', $user->id)->where('status', 'verified')->sum('amount');
        $donationCount = (int) DB::table('donations')
            ->where('user_id', $user->id)->where('status', 'verified')->count();
        $hours = (float) (DB::table('volunteers')->where('user_id', $user->id)->value('hours_rendered') ?? 0);

        $costPerMeal = (float) (Setting::getAll()['cost_per_meal'] ?? self::COST_PER_MEAL_DEFAULT) ?: self::COST_PER_MEAL_DEFAULT;
        $meals = (int) floor($donated / $costPerMeal);

        return response()->json([
            'donated_total' => $donated,
            'donation_count' => $donationCount,
            'volunteer_hours' => $hours,
            'meals_funded' => $meals,
            'badges' => $this->badges($donated, $donationCount, $hours),
        ]);
    }

    public function leaderboard()
    {
        $topDonors = PublicStats::topDonors();

        $topVolunteers = DB::table('volunteers')
            ->join('users', 'users.id', '=', 'volunteers.user_id')
            ->where('volunteers.hours_rendered', '>', 0)
            ->select('users.full_name', 'volunteers.hours_rendered as hours')
            ->orderByDesc('volunteers.hours_rendered')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['name' => PublicStats::maskName($r->full_name), 'hours' => (float) $r->hours])
            ->values();

        return response()->json(['top_donors' => $topDonors, 'top_volunteers' => $topVolunteers]);
    }

    /** All badges, each flagged earned/locked so the UI can show progress. */
    private function badges(float $donated, int $donationCount, float $hours): array
    {
        $defs = [
            ['key' => 'first_gift', 'icon' => '🎁', 'label' => 'First Gift', 'earned' => $donationCount >= 1, 'hint' => 'Make your first donation'],
            ['key' => 'generous', 'icon' => '💛', 'label' => 'Generous Heart', 'earned' => $donated >= 1000, 'hint' => 'Donate ₱1,000 total'],
            ['key' => 'champion', 'icon' => '🏆', 'label' => 'Shelter Champion', 'earned' => $donated >= 5000, 'hint' => 'Donate ₱5,000 total'],
            ['key' => 'recurring', 'icon' => '🔁', 'label' => 'Loyal Supporter', 'earned' => $donationCount >= 3, 'hint' => 'Donate 3 times'],
            ['key' => 'helping_hand', 'icon' => '🤝', 'label' => 'Helping Hand', 'earned' => $hours >= 10, 'hint' => 'Volunteer 10 hours'],
            ['key' => 'dedicated', 'icon' => '⭐', 'label' => 'Dedicated Volunteer', 'earned' => $hours >= 50, 'hint' => 'Volunteer 50 hours'],
        ];

        return array_map(fn ($b) => $b + ['earned' => (bool) $b['earned']], $defs);
    }
}
