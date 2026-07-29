<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Support\DonationCategories;
use App\Support\PublicStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Public, unauthenticated home-page analytics (hero stats, impact panel, transparency board,
 * featured animals). Extracted from the inline route closures in `routes/api_public.php`
 * (audit §0.2) so the public analytics live in a controller like the rest of the app.
 *
 * Behaviour is intentionally unchanged from the former closures; field-whitelisting and error
 * -contract hardening are tracked separately (audit §2 E).
 */
class PublicHomeController extends Controller
{
    /**
     * Fallback monthly donation goal (pesos) used when the `donation_monthly_goal` setting is
     * unset. Single source of truth — the SettingsAdmin input shows this same value as its
     * placeholder (audit §2 H-1).
     */
    public const DEFAULT_MONTHLY_GOAL = 80220;

    /** Hero "by the numbers" strip. */
    public function stats()
    {
        // These are derived metrics from existing tables.
        $animalsHelped = DB::table('animals')->whereIn('status', ['available', 'adopted', 'fostered', 'medical'])->count();

        $adoptionsCompleted = DB::table('adoption_applications')
            ->where('status', 'completed')
            ->count();

        $medicalTreatments = DB::table('medical_records')->count();

        $rescueReportsHandled = DB::table('rescue_reports')->count();

        return response()->json([
            'items' => [
                [
                    'value' => $animalsHelped.'+',
                    'label' => 'Animals currently helped',
                ],
                [
                    'value' => $adoptionsCompleted.'+',
                    'label' => 'Adoptions completed',
                ],
                [
                    'value' => $medicalTreatments.'+',
                    'label' => 'Medical treatments',
                ],
                [
                    'value' => $rescueReportsHandled.'+',
                    'label' => 'Rescue reports handled',
                ],
            ],
        ]);
    }

    /** Impact panel: lifetime aggregates + masked top donors. */
    public function impact()
    {
        $animalsRescued = DB::table('animals')->count();
        $animalsAdopted = DB::table('animals')->where('status', 'adopted')->count();
        $donationsRaised = (float) DB::table('donations')->where('status', 'verified')->sum('amount');
        $rescueReportsHandled = DB::table('rescue_reports')->count();
        $volunteersCount = DB::table('volunteers')->count();

        $completedApplications = DB::table('adoption_applications')->where('status', 'completed')->count();
        $declinedApplications = DB::table('adoption_applications')->where('status', 'declined')->count();
        $decidedApplications = $completedApplications + $declinedApplications;
        $successRate = $decidedApplications > 0 ? round(($completedApplications / $decidedApplications) * 100) : null;

        return response()->json([
            'animals_rescued' => $animalsRescued,
            'animals_adopted' => $animalsAdopted,
            'donations_raised' => $donationsRaised,
            'rescue_reports_handled' => $rescueReportsHandled,
            'volunteers_count' => $volunteersCount,
            'success_rate' => $successRate,
            'top_donors' => PublicStats::topDonors(),
        ]);
    }

    /** Transparency board: monthly goal/progress, totals, method split, 6-month trend, recent gifts. */
    public function transparency()
    {
        try {
            // Fresh verified-donations query each time (closures avoid builder mutation bugs).
            $verified = fn () => DB::table('donations')->where('status', 'verified');

            $monthStart = now()->startOfMonth();

            $totalRaised = (float) $verified()->sum('amount');
            $donationCount = (int) $verified()->count();
            $donorCount = (int) $verified()->distinct()->count('user_id');
            $thisMonthRaised = (float) $verified()->where('donated_at', '>=', $monthStart)->sum('amount');

            $monthlyGoal = (float) (DB::table('settings')->where('key', 'donation_monthly_goal')->value('value') ?: self::DEFAULT_MONTHLY_GOAL);
            $progressPct = $monthlyGoal > 0 ? (int) min(100, round(($thisMonthRaised / $monthlyGoal) * 100)) : 0;

            $byMethod = $verified()
                ->selectRaw('payment_method, COALESCE(SUM(amount), 0) as total')
                ->groupBy('payment_method')
                ->pluck('total', 'payment_method');

            // 6-month trend, bucketed in PHP to stay DB-agnostic (Postgres in prod, SQLite in dev).
            $buckets = [];
            for ($i = 5; $i >= 0; $i--) {
                $m = now()->startOfMonth()->subMonths($i);
                $buckets[$m->format('Y-m')] = ['label' => $m->format('M'), 'total' => 0.0];
            }
            $trendRows = $verified()
                ->where('donated_at', '>=', now()->startOfMonth()->subMonths(5))
                ->get(['amount', 'donated_at']);
            foreach ($trendRows as $r) {
                $key = Carbon::parse($r->donated_at)->format('Y-m');
                if (isset($buckets[$key])) {
                    $buckets[$key]['total'] += (float) $r->amount;
                }
            }
            $monthlyTrend = array_values($buckets);

            // Recent donations, honoring each donor's opt-in (named) vs anonymous choice.
            $recentDonations = DB::table('donations')
                ->leftJoin('users', 'users.id', '=', 'donations.user_id')
                ->where('donations.status', 'verified')
                ->orderByDesc('donations.donated_at')
                ->limit(10)
                ->get(['donations.amount', 'donations.donated_at', 'donations.is_anonymous', 'donations.category', 'users.full_name'])
                ->map(function ($d) {
                    $name = (! $d->is_anonymous && $d->full_name) ? PublicStats::maskName($d->full_name) : 'Anonymous';

                    return [
                        'name' => $name,
                        'amount' => (float) $d->amount,
                        'date' => $d->donated_at,
                        'category' => $d->category,
                    ];
                })
                ->values();

            $fundUsageKey = DB::table('settings')->where('key', 'fund_usage_image_path')->value('value');
            $fundUsageImage = $fundUsageKey ? Storage::url($fundUsageKey) : null;

            // Per-category allocation of THIS month's verified donations, with spillover: a category
            // donated past its goal (plus the "needed most" pool) is redistributed to the categories
            // that still fall short. Keyed to the same monthly window as the goal bar above.
            $categoryRows = $verified()
                ->where('donated_at', '>=', $monthStart)
                ->selectRaw('category, COALESCE(SUM(amount), 0) as total')
                ->groupBy('category')
                ->get();

            $direct = [];
            $needyPool = 0.0;
            foreach ($categoryRows as $row) {
                if ($row->category !== null && in_array($row->category, DonationCategories::keys(), true)) {
                    $direct[$row->category] = (float) $row->total;
                } else {
                    // null category or any legacy/unknown value → "where it's needed most".
                    $needyPool += (float) $row->total;
                }
            }
            $allocation = DonationCategories::allocate($direct, $needyPool);

            return response()->json([
                'monthly_goal' => $monthlyGoal,
                'this_month_raised' => $thisMonthRaised,
                'progress_pct' => $progressPct,
                'total_raised' => $totalRaised,
                'donation_count' => $donationCount,
                'donor_count' => $donorCount,
                'by_method' => $byMethod,
                'monthly_trend' => $monthlyTrend,
                'recent_donations' => $recentDonations,
                'fund_usage_image' => $fundUsageImage,
                'categories' => $allocation['categories'],
                'category_redistributed' => $allocation['redistributed_total'],
                'category_surplus' => $allocation['surplus'],
            ]);
        } catch (\Throwable $e) {
            // Log internally; never leak exception/SQL detail to anonymous clients.
            Log::error('Failed to load transparency data', ['exception' => $e]);

            return response()->json(['message' => 'Failed to load transparency data'], 500);
        }
    }

    /** Featured animals strip (one photo per animal, newest first). */
    public function featuredAnimals()
    {
        try {
            // In case tables/columns are not created yet, keep frontend alive.
            $animalsTableExists = DB::getSchemaBuilder()->hasTable('animals');
            $photosTableExists = DB::getSchemaBuilder()->hasTable('animal_photos');

            if (! $animalsTableExists) {
                return response()->json([
                    'animals' => [],
                ]);
            }

            // rescue_story backs the hover panel on the landing page's dog cards. Guarded the
            // same way as the tables above: an environment whose schema predates the column
            // still gets a valid response, just without the story.
            $hasStory = DB::getSchemaBuilder()->hasColumn('animals', 'rescue_story');

            $query = DB::table('animals')
                ->select([
                    'animals.id as id',
                    'animals.name',
                    'animals.species',
                    'animals.age',
                    'animals.status',
                ])
                ->whereNotIn('animals.status', ['archived', 'adopted'])
                ->orderByDesc('animals.id')
                ->limit(8);

            if ($hasStory) {
                $query = $query->addSelect('animals.rescue_story');
            }

            if ($photosTableExists) {
                // Pick exactly one photo per animal (prefer is_main, else the earliest row),
                // so an animal with multiple photo rows never fans out into duplicate cards.
                $mainPhoto = DB::table(DB::raw(
                    '(SELECT animal_id, photo_url, ROW_NUMBER() OVER (PARTITION BY animal_id ORDER BY is_main DESC, id ASC) AS rn FROM animal_photos) AS ranked_photos'
                ))->where('rn', 1);

                $query = $query->leftJoinSub($mainPhoto, 'animal_photos', 'animals.id', '=', 'animal_photos.animal_id')
                    ->addSelect('animal_photos.photo_url');
            } else {
                // Keep response shape stable.
                $query = $query->addSelect(DB::raw("'' as photo_url"));
            }

            $animals = $query->get();

            // Was an inline closure duplicating the same mapping; moved to Animal so the
            // adoption list and this page can't word the same status differently.
            $statusToLabel = fn ($status) => Animal::statusLabel($status);

            return response()->json([
                'animals' => $animals->map(function ($a) use ($statusToLabel) {
                    return [
                        'id' => (int) $a->id,
                        'name' => $a->name ?? 'Unnamed',
                        'species' => $a->species ?? 'Unknown',
                        'age' => is_numeric($a->age) ? ($a->age.' yrs') : ($a->age ?? 'N/A'),
                        'status' => $statusToLabel($a->status),
                        // Keep backward-compatible key expected by frontend. Resolve the
                        // stored key to an absolute URL so it works on any disk (local/S3).
                        'photo' => $a->photo_url ? Storage::url($a->photo_url) : '',
                        // Empty string rather than null so the frontend's truthiness check
                        // reads the same whether the column is missing or merely unfilled.
                        'story' => isset($a->rescue_story) ? (string) $a->rescue_story : '',
                    ];
                })->all(),
            ]);
        } catch (\Throwable $e) {
            // Log internally; return a generic message (no exception/SQL detail) to the browser.
            Log::error('Failed to load featured animals', ['exception' => $e]);

            return response()->json(['message' => 'Failed to load featured animals'], 500);
        }
    }
}
