<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MarksAdminRead;
use App\Models\RescueReport;
use App\Models\Team;
use App\Notifications\RescueAssignedToTeam;
use App\Rules\PhilippinePhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RescueReportController extends Controller
{
    use MarksAdminRead;

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reporter_name' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:20', new PhilippinePhone],
            'location' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string'],
            'urgency' => ['required', 'in:low,medium,high,critical'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        try {
            $photoPath = $request->hasFile('photo')
                ? $request->file('photo')->store('rescue-reports')
                : null;

            $report = RescueReport::create([
                'reporter_name' => $request->input('reporter_name'),
                'contact_number' => $request->input('contact_number'),
                'location' => $request->input('location'),
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'description' => $request->input('description'),
                'urgency' => $request->input('urgency'),
                'status' => 'pending',
                'photo_url' => $photoPath,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record rescue report', [
                'location' => $request->input('location'),
                'urgency' => $request->input('urgency'),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to submit report. Please try again.'], 500);
        }

        return response()->json([
            'report' => [
                'id' => $report->id,
                'status' => $report->status,
            ],
        ], 201);
    }

    public function index(Request $request)
    {
        $query = RescueReport::query()->with('team:id,name');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($teamId = $request->query('team_id')) {
            $query->where('team_id', (int) $teamId);
        }

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $reports = $query->orderByDesc('id')->paginate(12)->withQueryString();

        // Expose photo_url as an absolute URL (in place, just for serialization) so the
        // admin triage panel renders it regardless of the configured filesystem disk.
        $reports->getCollection()->transform(function (RescueReport $report) {
            if ($report->photo_url) {
                $report->photo_url = Storage::url($report->photo_url);
            }

            return $report;
        });

        return response()->json($reports);
    }

    public function updateStatus(Request $request, RescueReport $report)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', 'in:pending,assigned,in_progress,resolved'],
            'assigned_to' => ['nullable', 'string', 'max:150'],
            // The team sent to handle it — an active one (archived teams can't take new rescues).
            'team_id' => ['sometimes', 'nullable', 'integer', Rule::exists('teams', 'id')->where('is_active', true)],
            'admin_notes' => ['nullable', 'string'],
        ], ['team_id.exists' => 'Choose an active team.']);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (is_null($report->read_at)) {
            $data['read_at'] = now();
        }

        $newTeamId = array_key_exists('team_id', $data) && (int) $data['team_id'] !== (int) $report->team_id
            ? $data['team_id']
            : null;
        // Sending a team to a report nobody has picked up yet means it's been assigned.
        if ($newTeamId && ($data['status'] ?? $report->status) === 'pending') {
            $data['status'] = 'assigned';
        }

        try {
            $report->update($data);
        } catch (\Throwable $e) {
            Log::error('Failed to update rescue report', [
                'report_id' => $report->id,
                'payload' => $data,
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to update report. Please try again.'], 500);
        }

        // Tell the newly assigned team's members, so they can coordinate with their leader.
        if ($newTeamId) {
            $team = Team::with(['members.user', 'leader.user'])->find($newTeamId);
            foreach ($team->members as $member) {
                if ($member->user) {
                    (new RescueAssignedToTeam($report, $team))->sendTo($member->user);
                }
            }
        }

        return response()->json(['report' => $report->load('team:id,name')]);
    }

    /**
     * Marks a report read the first time an admin opens its triage panel, independent of
     * any status-changing action — without this, an admin who only reviews (but never
     * assigns/resolves) a report would never clear its unread highlight/badge count.
     */
    public function adminMarkRead(RescueReport $report)
    {
        $this->markReadOnce($report);

        return response()->json(['report' => $report]);
    }
}
