<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated metrics for the admin Insights dashboard. All time-bucketing is done in PHP
 * (not SQL date functions) so the same queries work on Postgres (prod) and SQLite (tests).
 */
class AnalyticsController extends Controller
{
    public function overview()
    {
        return response()->json([
            'summary' => $this->summary(),
            'flow_by_month' => $this->flowByMonth(),
            'donations_by_month' => $this->donationsByMonth(),
            'species_mix' => $this->speciesMix(),
            'avg_length_of_stay_days' => $this->avgLengthOfStayDays(),
            'live_release_rate' => $this->liveReleaseRate(),
        ]);
    }

    /** Headline counts shown as cards. */
    private function summary(): array
    {
        $byStatus = DB::table('animals')
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'total_animals' => (int) DB::table('animals')->count(),
            'available' => (int) ($byStatus['available'] ?? 0),
            'adopted' => (int) ($byStatus['adopted'] ?? 0),
            'fostered' => (int) ($byStatus['fostered'] ?? 0),
            'in_medical' => (int) ($byStatus['medical'] ?? 0),
            'verified_donations_total' => (float) DB::table('donations')
                ->where('status', 'verified')->sum('amount'),
        ];
    }

    /** Empty 12-month buckets keyed Y-m, oldest first. */
    private function emptyMonths(): array
    {
        $buckets = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $buckets[$m->format('Y-m')] = ['label' => $m->format('M'), 'intakes' => 0, 'adoptions' => 0];
        }

        return $buckets;
    }

    /** Animals taken in vs adoptions finalised, per month. */
    private function flowByMonth(): array
    {
        $buckets = $this->emptyMonths();
        $since = now()->startOfMonth()->subMonths(11);

        $intakes = DB::table('animals')->where('created_at', '>=', $since)->pluck('created_at');
        foreach ($intakes as $date) {
            $key = Carbon::parse($date)->format('Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]['intakes']++;
            }
        }

        $adoptions = DB::table('adoption_applications')
            ->where('status', 'completed')
            ->where('created_at', '>=', $since)
            ->pluck('created_at');
        foreach ($adoptions as $date) {
            $key = Carbon::parse($date)->format('Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]['adoptions']++;
            }
        }

        return array_values($buckets);
    }

    /** Verified donation totals per month. */
    private function donationsByMonth(): array
    {
        $buckets = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $buckets[$m->format('Y-m')] = ['label' => $m->format('M'), 'total' => 0.0];
        }

        $rows = DB::table('donations')
            ->where('status', 'verified')
            ->where('donated_at', '>=', now()->startOfMonth()->subMonths(11))
            ->get(['amount', 'donated_at']);

        foreach ($rows as $r) {
            $key = Carbon::parse($r->donated_at)->format('Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]['total'] += (float) $r->amount;
            }
        }

        return array_values($buckets);
    }

    private function speciesMix(): array
    {
        return DB::table('animals')
            ->selectRaw('species, COUNT(*) as count')
            ->groupBy('species')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['species' => $r->species ?: 'Unknown', 'count' => (int) $r->count])
            ->all();
    }

    /**
     * Average days from intake (animal created) to a finalised adoption. Computed in PHP over
     * completed applications joined to their animal, to stay DB-agnostic. The application's
     * created_at stands in for the adoption date (the table tracks no separate completion time).
     */
    private function avgLengthOfStayDays(): ?float
    {
        $rows = DB::table('adoption_applications')
            ->join('animals', 'animals.id', '=', 'adoption_applications.animal_id')
            ->where('adoption_applications.status', 'completed')
            ->get(['adoption_applications.created_at as adopted_at', 'animals.created_at as intake_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $totalDays = 0;
        foreach ($rows as $r) {
            $totalDays += abs(Carbon::parse($r->intake_at)->diffInDays(Carbon::parse($r->adopted_at)));
        }

        return round($totalDays / $rows->count(), 1);
    }

    /**
     * Live-release rate: live outcomes (adopted + fostered) over all completed outcomes
     * (adopted + fostered + archived). Archived stands in for non-live outcomes since the
     * app doesn't track euthanasia/death. Returns a percentage.
     */
    private function liveReleaseRate(): float
    {
        $byStatus = DB::table('animals')
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $live = (int) ($byStatus['adopted'] ?? 0) + (int) ($byStatus['fostered'] ?? 0);
        $outcomes = $live + (int) ($byStatus['archived'] ?? 0);

        return $outcomes > 0 ? round(($live / $outcomes) * 100, 1) : 0.0;
    }
}
