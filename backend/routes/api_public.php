<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AnimalController;
use App\Http\Controllers\RescueReportController;
use App\Http\Controllers\SettingController;

Route::get('/home/settings', [SettingController::class, 'publicIndex']);

Route::get('/test', function () {
    return response()->json([
        'message' => 'Laravel API is working fine'
    ]);
});

// ---- Animal browse + detail (Phase 2) ----
Route::get('/animals', [AnimalController::class, 'index']);
Route::get('/animals/{animal}', [AnimalController::class, 'show']);

// ---- Rescue report submission (Phase 4) ----
Route::post('/rescue-reports', [RescueReportController::class, 'store']);

// ---- Public Home API (DB-backed) ----
// Note: uses raw table access for now since models may not exist yet.
use Illuminate\Support\Facades\DB;

Route::get('/home/stats', function () {
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
                'value' => $animalsHelped . '+',
                'label' => 'Animals currently helped',
            ],
            [
                'value' => $adoptionsCompleted . '+',
                'label' => 'Adoptions completed',
            ],
            [
                'value' => $medicalTreatments . '+',
                'label' => 'Medical treatments',
            ],
            [
                'value' => $rescueReportsHandled . '+',
                'label' => 'Rescue reports handled',
            ],
        ],
    ]);
});

Route::get('/home/impact', function () {
    $animalsRescued = DB::table('animals')->count();
    $animalsAdopted = DB::table('animals')->where('status', 'adopted')->count();
    $donationsRaised = (float) DB::table('donations')->where('status', 'verified')->sum('amount');
    $rescueReportsHandled = DB::table('rescue_reports')->count();
    $volunteersCount = DB::table('volunteers')->count();

    $completedApplications = DB::table('adoption_applications')->where('status', 'completed')->count();
    $declinedApplications = DB::table('adoption_applications')->where('status', 'declined')->count();
    $decidedApplications = $completedApplications + $declinedApplications;
    $successRate = $decidedApplications > 0 ? round(($completedApplications / $decidedApplications) * 100) : null;

    $topDonors = DB::table('donations')
        ->join('users', 'users.id', '=', 'donations.user_id')
        ->where('donations.status', 'verified')
        ->select('users.full_name', DB::raw('SUM(donations.amount) as total'))
        ->groupBy('users.id', 'users.full_name')
        ->orderByDesc('total')
        ->limit(5)
        ->get()
        ->map(function ($row) {
            // Show "First L." rather than a donor's full legal name on a public page.
            $parts = preg_split('/\s+/', trim($row->full_name));
            $displayName = $parts[0];
            if (count($parts) > 1) {
                $displayName .= ' ' . mb_substr(end($parts), 0, 1) . '.';
            }
            return [
                'name' => $displayName,
                'total' => (float) $row->total,
            ];
        })
        ->values();

    return response()->json([
        'animals_rescued' => $animalsRescued,
        'animals_adopted' => $animalsAdopted,
        'donations_raised' => $donationsRaised,
        'rescue_reports_handled' => $rescueReportsHandled,
        'volunteers_count' => $volunteersCount,
        'success_rate' => $successRate,
        'top_donors' => $topDonors,
    ]);
});

Route::get('/home/featured-animals', function () {
    try {
        // In case tables/columns are not created yet, keep frontend alive.
        $animalsTableExists = DB::getSchemaBuilder()->hasTable('animals');
        $photosTableExists = DB::getSchemaBuilder()->hasTable('animal_photos');

        if (!$animalsTableExists) {
            return response()->json([
                'animals' => [],
            ]);
        }

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

        if ($photosTableExists) {
            // Pick exactly one photo per animal (prefer is_main, else the earliest row),
            // so an animal with multiple photo rows never fans out into duplicate cards.
            $mainPhoto = DB::table(DB::raw(
                '(SELECT animal_id, photo_url, ROW_NUMBER() OVER (PARTITION BY animal_id ORDER BY is_main DESC, id ASC) AS rn FROM animal_photos) AS ranked_photos'
            ))->where('rn', 1);

$query = $query->leftJoinSub($mainPhoto, 'animal_photos', 'animals.id', '=', 'animal_photos.animal_id')
                ->addSelect([
                    'animal_photos.photo_url',
                    // Provide a more predictable image URL for the frontend.
                    DB::raw("CASE WHEN animal_photos.photo_url IS NULL OR animal_photos.photo_url = '' THEN '' ELSE CONCAT(COALESCE(CONCAT('".rtrim(env('APP_URL',''),'/')."'), ''), '/', animal_photos.photo_url) END as photo_url_abs")
                ]);


        } else {
            // Keep response shape stable.
            $query = $query->addSelect(DB::raw("'' as photo_url"));
        }

        $animals = $query->get();

        $statusToLabel = function ($status) {
            return match ($status) {
                'available' => 'Available for adoption',
                'adopted' => 'Adopted',
                'fostered' => 'In foster care',
                'medical' => 'Medical recovery',
                default => 'Status: ' . $status,
            };
        };

        return response()->json([
            'animals' => $animals->map(function ($a) use ($statusToLabel) {
                return [
                    'id' => (int) $a->id,
                    'name' => $a->name ?? 'Unnamed',
                    'species' => $a->species ?? 'Unknown',
                    'age' => is_numeric($a->age) ? ($a->age . ' yrs') : ($a->age ?? 'N/A'),
                    'status' => $statusToLabel($a->status),
                    // Keep backward-compatible key expected by frontend.
                    'photo' => $a->photo_url ?: '',
                ];
            })->all(),
        ]);
    } catch (\Throwable $e) {
        // Prevent browser from receiving an empty response.
        return response()->json([
            'message' => 'Failed to load featured animals',
            'error' => $e->getMessage(),
        ], 500);
    }
});


