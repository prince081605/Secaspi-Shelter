<?php

namespace App\Http\Controllers;

use App\Models\AdoptionApplication;
use App\Models\Animal;
use App\Models\Donation;
use App\Models\FosterApplication;
use App\Models\RescueReport;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function adminOverview()
    {
        $inCare = Animal::whereIn('status', ['available', 'medical', 'quarantine', 'fostered'])->count();
        $adoptedThisYear = AdoptionApplication::where('status', 'completed')
            ->whereYear('created_at', now()->year)
            ->count();
        $readyForAdoption = Animal::where('status', 'available')->count();
        $donationsThisMonth = Donation::where('status', 'verified')
            ->whereMonth('donated_at', now()->month)
            ->whereYear('donated_at', now()->year)
            ->sum('amount');

        $stats = [
            ['key' => 'inCare', 'label' => 'Animals in care', 'value' => $inCare, 'sub' => 'Available, fostered, or in treatment'],
            ['key' => 'adoptedThisYear', 'label' => 'Adopted this year', 'value' => $adoptedThisYear, 'sub' => now()->year],
            ['key' => 'readyForAdoption', 'label' => 'Ready for adoption', 'value' => $readyForAdoption, 'sub' => 'Status: available'],
            ['key' => 'donationsMonth', 'label' => 'Donations (month)', 'value' => '₱' . number_format($donationsThisMonth, 2), 'sub' => now()->format('F Y')],
        ];

        $statusBreakdown = Animal::where('status', '!=', 'archived')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');
        $totalAnimals = max($statusBreakdown->sum(), 1);

        $statusLabels = ['available' => 'Ready', 'medical' => 'Under treatment', 'quarantine' => 'Quarantine', 'fostered' => 'In foster', 'adopted' => 'Adopted'];
        $analytics = [];
        foreach ($statusLabels as $status => $label) {
            $count = (int) ($statusBreakdown[$status] ?? 0);
            $analytics[] = [
                'label' => $label,
                'count' => $count,
                'value' => round($count / $totalAnimals * 100),
            ];
        }

        $activity = collect()
            ->merge(RescueReport::orderByDesc('id')->limit(5)->get()->map(fn ($r) => [
                'type' => 'rescue_report',
                'label' => 'New rescue report: ' . ($r->location ?: 'unknown location'),
                'status' => $r->status,
                'created_at' => $r->created_at,
            ]))
            ->merge(Donation::orderByDesc('id')->limit(5)->get()->map(fn ($d) => [
                'type' => 'donation',
                'label' => 'Donation ' . $d->reference_no . ' (₱' . number_format($d->amount, 2) . ')',
                'status' => $d->status,
                'created_at' => $d->created_at,
            ]))
            ->merge(AdoptionApplication::orderByDesc('id')->limit(5)->get()->map(fn ($a) => [
                'type' => 'adoption_application',
                'label' => 'Adoption application ' . $a->reference_no,
                'status' => $a->status,
                'created_at' => $a->created_at,
            ]))
            ->merge(FosterApplication::orderByDesc('id')->limit(5)->get()->map(fn ($f) => [
                'type' => 'foster_application',
                'label' => 'Foster application #' . $f->id,
                'status' => $f->status,
                'created_at' => $f->created_at,
            ]))
            ->sortByDesc('created_at')
            ->take(10)
            ->values();

        return response()->json([
            'stats' => $stats,
            'analytics' => $analytics,
            'activity' => $activity,
        ]);
    }
}
