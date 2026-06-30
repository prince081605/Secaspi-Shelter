<?php

namespace App\Http\Controllers;

use App\Models\AdoptionApplication;
use App\Models\Animal;
use App\Models\Donation;
use App\Models\FosterApplication;
use App\Models\Reminder;
use App\Models\RescueReport;
use App\Models\Visitation;
use App\Models\VolunteerApplication;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * All the admin sidebar "needs attention" badge counts in a single request, replacing the
     * dashboard's 7 separate per-resource polls every 30s (audit §11 D-1). Mirrors exactly what
     * those calls measured: unread rescue/adoption, pending foster/donation/visitation/volunteer,
     * and overdue reminders.
     */
    public function pendingCounts()
    {
        $today = now()->startOfDay();

        return response()->json([
            'rescue' => RescueReport::whereNull('read_at')->count(),
            'adoption' => AdoptionApplication::whereNull('read_at')->count(),
            'foster' => FosterApplication::where('status', 'pending')->count(),
            'donation' => Donation::where('status', 'pending')->count(),
            'visitation' => Visitation::where('status', 'pending')->count(),
            'volunteer' => VolunteerApplication::where('status', 'pending')->count(),
            'reminders_overdue' => Reminder::whereIn('status', ['pending', 'sent'])
                ->whereDate('reminder_date', '<', $today)
                ->count(),
        ]);
    }

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
            ['key' => 'donationsMonth', 'label' => 'Donations (month)', 'value' => '₱'.number_format($donationsThisMonth, 2), 'sub' => now()->format('F Y')],
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
                'label' => 'New rescue report: '.($r->location ?: 'unknown location'),
                'status' => $r->status,
                'created_at' => $r->created_at,
            ]))
            ->merge(Donation::orderByDesc('id')->limit(5)->get()->map(fn ($d) => [
                'type' => 'donation',
                'label' => 'Donation '.$d->reference_no.' (₱'.number_format($d->amount, 2).')',
                'status' => $d->status,
                // The donations table timestamps as `donated_at`, not `created_at`; without this
                // the activity row showed a blank date and sorted to the bottom.
                'created_at' => $d->donated_at,
            ]))
            ->merge(AdoptionApplication::orderByDesc('id')->limit(5)->get()->map(fn ($a) => [
                'type' => 'adoption_application',
                'label' => 'Adoption application '.$a->reference_no,
                'status' => $a->status,
                'created_at' => $a->created_at,
            ]))
            ->merge(FosterApplication::orderByDesc('id')->limit(5)->get()->map(fn ($f) => [
                'type' => 'foster_application',
                'label' => 'Foster application #'.$f->id,
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
