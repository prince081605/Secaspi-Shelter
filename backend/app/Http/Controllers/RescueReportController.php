<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MarksAdminRead;
use App\Models\RescueReport;
use App\Models\Team;
use App\Notifications\RescueAssignedToTeam;
use App\Rules\PhilippinePhone;
use App\Services\ReportScreening;
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

        // Anyone may report, but a blocked reporter, or one over the daily limit, is turned away
        // with the shelter's number instead. The token is read when one is sent, though none is needed.
        $user = auth('sanctum')->user();
        $contactHash = ReportScreening::contactHash($request->input('contact_number'));
        $ipHash = ReportScreening::ipHash($request->ip());

        if (ReportScreening::blockFor($contactHash, $user?->id)) {
            return response()->json([
                'message' => 'Rescue reports from this number or account can no longer be accepted online. '.ReportScreening::callTheShelter(),
            ], 403);
        }
        if ($overLimit = ReportScreening::overDailyLimit($contactHash, $user?->id, $ipHash)) {
            return response()->json(['message' => $overLimit.' '.ReportScreening::callTheShelter()], 429);
        }

        $duplicate = ReportScreening::findPossibleDuplicate(
            $request->filled('latitude') ? (float) $request->input('latitude') : null,
            $request->filled('longitude') ? (float) $request->input('longitude') : null,
            $request->input('location'),
        );

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
                'contact_hash' => $contactHash,
                'ip_hash' => $ipHash,
                'user_id' => $user?->id,
                'possible_duplicate_of_id' => $duplicate?->id,
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
        $query = RescueReport::query()->with(['team:id,name', 'user:id,full_name', 'possibleDuplicate', 'duplicateOf']);

        // Reports closed as invalid (fake, duplicate, not found) only show when asked for.
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'invalid');
        }

        if ($teamId = $request->query('team_id')) {
            $query->where('team_id', (int) $teamId);
        }

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $reports = $query->orderByDesc('id')->paginate(12)->withQueryString();

        $reports->getCollection()->transform(fn (RescueReport $report) => $this->present($report));

        return response()->json($reports);
    }

    /**
     * A report as the triage panel shows it: the photo as an absolute URL (whatever the disk),
     * what's known about the reporter, and any report it may repeat. The hashes stay hidden.
     */
    private function present(RescueReport $report): array
    {
        $summary = fn (?RescueReport $r) => $r ? [
            'id' => $r->id,
            'location' => $r->location,
            'status' => $r->status,
            'created_at' => $r->created_at,
        ] : null;

        return [
            ...$report->toArray(),
            'photo_url' => $report->photo_url ? Storage::url($report->photo_url) : null,
            'reporter_account' => $report->user?->full_name,
            'reporter_history' => ReportScreening::history($report),
            'reporter_blocked' => (bool) ReportScreening::blockFor($report->contact_hash, $report->user_id),
            'can_block' => (bool) ($report->contact_hash || $report->user_id),
            'possible_duplicate' => $summary($report->possibleDuplicate),
            'duplicate_of' => $summary($report->duplicateOf),
            'invalid_reason_label' => ReportScreening::INVALID_REASONS[$report->invalid_reason] ?? null,
        ];
    }

    public function updateStatus(Request $request, RescueReport $report)
    {
        $validator = Validator::make($request->all(), [
            // 'invalid' closes a report that needs no rescue: fake, a duplicate, or the animal
            // wasn't there. It's kept apart from 'resolved' so it never counts as a rescue.
            'status' => ['sometimes', 'in:pending,assigned,in_progress,resolved,invalid'],
            'assigned_to' => ['nullable', 'string', 'max:150'],
            // The team sent to handle it — an active one (archived teams can't take new rescues).
            'team_id' => ['sometimes', 'nullable', 'integer', Rule::exists('teams', 'id')->where('is_active', true)],
            'admin_notes' => ['nullable', 'string'],
            'invalid_reason' => ['nullable', 'required_if:status,invalid', Rule::in(array_keys(ReportScreening::INVALID_REASONS))],
            'invalid_note' => ['nullable', 'string', 'max:1000'],
            'duplicate_of_id' => ['nullable', 'required_if:invalid_reason,duplicate', 'integer', 'exists:rescue_reports,id', Rule::notIn([$report->id])],
        ], [
            'team_id.exists' => 'Choose an active team.',
            'invalid_reason.required_if' => 'Choose why this report is invalid.',
            'duplicate_of_id.required_if' => 'Enter the number of the report this one repeats.',
            'duplicate_of_id.exists' => 'There is no report with that number.',
            'duplicate_of_id.not_in' => 'A report cannot be a duplicate of itself.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (is_null($report->read_at)) {
            $data['read_at'] = now();
        }

        // The reason only goes with an invalid report; reopening one clears it.
        $newStatus = $data['status'] ?? $report->status;
        if ($newStatus !== 'invalid') {
            $data['invalid_reason'] = null;
            $data['invalid_note'] = null;
            $data['duplicate_of_id'] = null;
        } elseif (($data['invalid_reason'] ?? $report->invalid_reason) !== 'duplicate') {
            $data['duplicate_of_id'] = null;
        }

        $newTeamId = array_key_exists('team_id', $data) && (int) $data['team_id'] !== (int) $report->team_id
            ? $data['team_id']
            : null;
        // Nobody is sent to a report that's being closed as invalid.
        if ($newStatus === 'invalid') {
            $newTeamId = null;
            unset($data['team_id']);
        }
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

        return response()->json(['report' => $this->present($report->load(['team:id,name', 'user:id,full_name', 'possibleDuplicate', 'duplicateOf']))]);
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
