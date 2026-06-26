<?php

namespace App\Http\Controllers;

use App\Models\AdoptionApplication;
use App\Models\Animal;
use App\Models\Donation;
use App\Models\MedicalRecord;
use App\Models\RescueReport;
use App\Models\Vaccination;
use App\Models\Volunteer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    private const TYPES = ['adoption', 'animals', 'medical', 'donations', 'volunteers', 'staff', 'rescue'];

    private const TYPE_LABELS = [
        'adoption' => 'Adoption Applications Report',
        'animals' => 'Animals Report',
        'medical' => 'Medical & Vaccinations Report',
        'donations' => 'Donations Report',
        'volunteers' => 'Volunteers Report',
        'staff' => 'Staff Report',
        'rescue' => 'Rescue Reports Report',
    ];

    public function adoption(Request $request)
    {
        return response()->json($this->adoptionData($request));
    }

    public function animals(Request $request)
    {
        return response()->json($this->animalsData($request));
    }

    public function medical(Request $request)
    {
        return response()->json($this->medicalData($request));
    }

    public function donations(Request $request)
    {
        return response()->json($this->donationsData($request));
    }

    public function volunteers(Request $request)
    {
        return response()->json($this->volunteersData($request));
    }

    public function staff(Request $request)
    {
        return response()->json($this->staffData($request));
    }

    public function rescue(Request $request)
    {
        return response()->json($this->rescueData($request));
    }

    public function exportCsv(Request $request)
    {
        $data = $this->resolveData($request);

        $filename = $request->query('type') . '-report-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($data['columns'], 'label'));
            foreach ($data['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => $row[$c['key']] ?? '', $data['columns']));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportPdf(Request $request)
    {
        $data = $this->resolveData($request);
        $type = $request->query('type');

        $pdf = Pdf::loadView('reports.pdf', [
            'title' => self::TYPE_LABELS[$type] ?? ucfirst($type),
            'generatedAt' => now()->format('M j, Y g:i A'),
            'summary' => $data['summary'],
            'columns' => $data['columns'],
            'rows' => $data['rows'],
        ]);

        $filename = $type . '-report-' . now()->format('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }

    private function resolveData(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'in:' . implode(',', self::TYPES)],
        ]);

        if ($validator->fails()) {
            abort(422, 'Unknown or missing report type.');
        }

        // The exports are staff-accessible (route gate), but financial data is admin-only:
        // block exporting the donations report for anyone below admin. Mirrors the
        // admin-only gate on GET /admin/reports/donations.
        if ($request->query('type') === 'donations' && ! $request->user()->hasRoleAtLeast('admin')) {
            abort(403, 'Forbidden');
        }

        return match ($request->query('type')) {
            'adoption' => $this->adoptionData($request),
            'animals' => $this->animalsData($request),
            'medical' => $this->medicalData($request),
            'donations' => $this->donationsData($request),
            'volunteers' => $this->volunteersData($request),
            'staff' => $this->staffData($request),
            'rescue' => $this->rescueData($request),
        };
    }

    private function adoptionData(Request $request): array
    {
        $query = AdoptionApplication::query()->with(['animal', 'user']);
        $this->applyDateRange($query, $request, 'created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $statusCounts = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $applications = $query->orderByDesc('id')->get();

        return [
            'summary' => [
                ['label' => 'Pending', 'value' => (int) ($statusCounts['pending'] ?? 0)],
                ['label' => 'Approved', 'value' => (int) ($statusCounts['approved'] ?? 0)],
                ['label' => 'Declined', 'value' => (int) ($statusCounts['declined'] ?? 0)],
                ['label' => 'Completed', 'value' => (int) ($statusCounts['completed'] ?? 0)],
                ['label' => 'Total', 'value' => $applications->count()],
            ],
            'columns' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'reference_no', 'label' => 'Reference'],
                ['key' => 'animal_name', 'label' => 'Animal'],
                ['key' => 'applicant_name', 'label' => 'Applicant'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_at', 'label' => 'Submitted'],
            ],
            'rows' => $applications->map(fn (AdoptionApplication $a) => [
                'id' => $a->id,
                'reference_no' => $a->reference_no,
                'animal_name' => $a->animal->name ?? '—',
                'applicant_name' => $a->full_name ?: ($a->user->full_name ?? '—'),
                'status' => $a->status,
                'created_at' => (string) $a->created_at,
            ])->values()->all(),
        ];
    }

    private function animalsData(Request $request): array
    {
        $query = Animal::query();

        if ($species = $request->query('species')) {
            $query->where('species', $species);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $statusCounts = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $animals = $query->orderByDesc('id')->get();

        $statusLabels = ['available' => 'Available', 'adopted' => 'Adopted', 'fostered' => 'Fostered', 'medical' => 'Medical', 'quarantine' => 'Quarantine', 'archived' => 'Archived'];
        $summary = [];
        foreach ($statusLabels as $key => $label) {
            $summary[] = ['label' => $label, 'value' => (int) ($statusCounts[$key] ?? 0)];
        }
        $summary[] = ['label' => 'Total', 'value' => $animals->count()];

        return [
            'summary' => $summary,
            'columns' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'species', 'label' => 'Species'],
                ['key' => 'breed', 'label' => 'Breed'],
                ['key' => 'gender', 'label' => 'Gender'],
                ['key' => 'size', 'label' => 'Size'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $animals->map(fn (Animal $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'species' => $a->species,
                'breed' => $a->breed ?: '—',
                'gender' => $a->gender ?: '—',
                'size' => $a->size ?: '—',
                'status' => $a->status,
            ])->values()->all(),
        ];
    }

    private function medicalData(Request $request): array
    {
        $recordsQuery = MedicalRecord::query()->with('animal');
        $this->applyDateRange($recordsQuery, $request, 'record_date');
        if ($type = $request->query('record_type')) {
            $recordsQuery->where('type', $type);
        }
        $records = $recordsQuery->get();

        $vaccinationsQuery = Vaccination::query()->with('animal');
        $this->applyDateRange($vaccinationsQuery, $request, 'date_given');
        $vaccinations = $request->query('record_type') ? collect() : $vaccinationsQuery->get();

        $merged = collect()
            ->merge($records->map(fn (MedicalRecord $m) => [
                'animal_name' => $m->animal->name ?? '—',
                'kind' => 'Medical',
                'type' => $m->type,
                'date' => (string) $m->record_date,
                'detail' => $m->description ?: ($m->vet_name ?: '—'),
            ]))
            ->merge($vaccinations->map(fn (Vaccination $v) => [
                'animal_name' => $v->animal->name ?? '—',
                'kind' => 'Vaccination',
                'type' => $v->vaccine_name,
                'date' => (string) $v->date_given,
                'detail' => $v->next_due ? "Next due: {$v->next_due}" : '—',
            ]))
            ->sortByDesc('date')
            ->values();

        $typeCounts = $records->countBy('type');

        $summary = [];
        foreach (['vaccination', 'deworming', 'treatment', 'surgery', 'checkup', 'emergency'] as $t) {
            $summary[] = ['label' => ucfirst($t), 'value' => (int) ($typeCounts[$t] ?? 0)];
        }
        $summary[] = ['label' => 'Vaccinations given', 'value' => $vaccinations->count()];
        $summary[] = ['label' => 'Total entries', 'value' => $merged->count()];

        return [
            'summary' => $summary,
            'columns' => [
                ['key' => 'animal_name', 'label' => 'Animal'],
                ['key' => 'kind', 'label' => 'Kind'],
                ['key' => 'type', 'label' => 'Type / Vaccine'],
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'detail', 'label' => 'Detail'],
            ],
            'rows' => $merged->all(),
        ];
    }

    private function donationsData(Request $request): array
    {
        $query = Donation::query()->with('user');
        $this->applyDateRange($query, $request, 'donated_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($method = $request->query('payment_method')) {
            $query->where('payment_method', $method);
        }

        $statusTotals = (clone $query)
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $byMethod = (clone $query)
            ->where('status', 'verified')
            ->selectRaw('payment_method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $donations = $query->orderByDesc('id')->get();

        $summary = [
            ['label' => 'Pending', 'value' => (int) ($statusTotals['pending']->count ?? 0)],
            ['label' => 'Verified', 'value' => (int) ($statusTotals['verified']->count ?? 0)],
            ['label' => 'Rejected', 'value' => (int) ($statusTotals['rejected']->count ?? 0)],
            ['label' => 'Verified total', 'value' => '₱' . number_format($statusTotals['verified']->total ?? 0, 2)],
            ['label' => 'Pending total', 'value' => '₱' . number_format($statusTotals['pending']->total ?? 0, 2)],
        ];
        foreach ($byMethod as $method => $total) {
            $summary[] = ['label' => ucfirst($method) . ' (verified)', 'value' => '₱' . number_format($total, 2)];
        }

        return [
            'summary' => $summary,
            'columns' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'reference_no', 'label' => 'Reference'],
                ['key' => 'donor_name', 'label' => 'Donor'],
                ['key' => 'amount', 'label' => 'Amount'],
                ['key' => 'payment_method', 'label' => 'Method'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'donated_at', 'label' => 'Date'],
            ],
            'rows' => $donations->map(fn (Donation $d) => [
                'id' => $d->id,
                'reference_no' => $d->reference_no,
                'donor_name' => $d->user->full_name ?? '—',
                'amount' => '₱' . number_format($d->amount, 2),
                'payment_method' => $d->payment_method,
                'status' => $d->status,
                'donated_at' => (string) $d->donated_at,
            ])->values()->all(),
        ];
    }

    private function volunteersData(Request $request): array
    {
        $volunteers = Volunteer::query()->with(['user', 'tasks'])->where('type', 'volunteer')->orderByDesc('id')->get();

        $taskStatusCounts = ['assigned' => 0, 'ongoing' => 0, 'completed' => 0];
        foreach ($volunteers as $v) {
            foreach ($v->tasks as $task) {
                if (isset($taskStatusCounts[$task->status])) {
                    $taskStatusCounts[$task->status]++;
                }
            }
        }

        return [
            'summary' => [
                ['label' => 'Total volunteers', 'value' => $volunteers->count()],
                ['label' => 'Total hours rendered', 'value' => (int) $volunteers->sum('hours_rendered')],
                ['label' => 'Tasks assigned', 'value' => $taskStatusCounts['assigned']],
                ['label' => 'Tasks ongoing', 'value' => $taskStatusCounts['ongoing']],
                ['label' => 'Tasks completed', 'value' => $taskStatusCounts['completed']],
            ],
            'columns' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'availability', 'label' => 'Availability'],
                ['key' => 'hours_rendered', 'label' => 'Hours'],
                ['key' => 'task_count', 'label' => 'Tasks'],
            ],
            'rows' => $volunteers->map(fn (Volunteer $v) => [
                'name' => $v->user->full_name ?? '—',
                'availability' => $v->availability ?: '—',
                'hours_rendered' => (int) $v->hours_rendered,
                'task_count' => $v->tasks->count(),
            ])->values()->all(),
        ];
    }

    private function staffData(Request $request): array
    {
        $staff = Volunteer::query()->with(['user', 'tasks'])->where('type', 'staff')->orderByDesc('id')->get();

        $taskStatusCounts = ['assigned' => 0, 'ongoing' => 0, 'completed' => 0];
        foreach ($staff as $s) {
            foreach ($s->tasks as $task) {
                if (isset($taskStatusCounts[$task->status])) {
                    $taskStatusCounts[$task->status]++;
                }
            }
        }

        return [
            'summary' => [
                ['label' => 'Total staff', 'value' => $staff->count()],
                ['label' => 'Total hours rendered', 'value' => (int) $staff->sum('hours_rendered')],
                ['label' => 'Tasks assigned', 'value' => $taskStatusCounts['assigned']],
                ['label' => 'Tasks ongoing', 'value' => $taskStatusCounts['ongoing']],
                ['label' => 'Tasks completed', 'value' => $taskStatusCounts['completed']],
            ],
            'columns' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'availability', 'label' => 'Availability'],
                ['key' => 'hours_rendered', 'label' => 'Hours'],
                ['key' => 'task_count', 'label' => 'Tasks'],
            ],
            'rows' => $staff->map(fn (Volunteer $s) => [
                'name' => $s->user->full_name ?? '—',
                'availability' => $s->availability ?: '—',
                'hours_rendered' => (int) $s->hours_rendered,
                'task_count' => $s->tasks->count(),
            ])->values()->all(),
        ];
    }

    private function rescueData(Request $request): array
    {
        $query = RescueReport::query();
        $this->applyDateRange($query, $request, 'created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($urgency = $request->query('urgency')) {
            $query->where('urgency', $urgency);
        }

        $statusCounts = (clone $query)->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->pluck('count', 'status');
        $urgencyCounts = (clone $query)->select('urgency', DB::raw('COUNT(*) as count'))->groupBy('urgency')->pluck('count', 'urgency');

        $reports = $query->orderByDesc('id')->get();

        $summary = [];
        foreach (['pending' => 'Pending', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'resolved' => 'Resolved'] as $key => $label) {
            $summary[] = ['label' => $label, 'value' => (int) ($statusCounts[$key] ?? 0)];
        }
        foreach (['low' => 'Low urgency', 'medium' => 'Medium urgency', 'high' => 'High urgency', 'critical' => 'Critical urgency'] as $key => $label) {
            $summary[] = ['label' => $label, 'value' => (int) ($urgencyCounts[$key] ?? 0)];
        }

        return [
            'summary' => $summary,
            'columns' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'reporter_name', 'label' => 'Reporter'],
                ['key' => 'location', 'label' => 'Location'],
                ['key' => 'urgency', 'label' => 'Urgency'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_at', 'label' => 'Reported'],
            ],
            'rows' => $reports->map(fn (RescueReport $r) => [
                'id' => $r->id,
                'reporter_name' => $r->reporter_name ?: '—',
                'location' => $r->location ?: '—',
                'urgency' => $r->urgency,
                'status' => $r->status,
                'created_at' => (string) $r->created_at,
            ])->values()->all(),
        ];
    }

    private function applyDateRange($query, Request $request, string $column): void
    {
        if ($from = $request->query('from')) {
            $query->whereDate($column, '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate($column, '<=', $to);
        }
    }
}
