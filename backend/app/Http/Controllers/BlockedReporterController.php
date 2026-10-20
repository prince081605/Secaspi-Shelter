<?php

namespace App\Http\Controllers;

use App\Models\BlockedReporter;
use App\Models\RescueReport;
use App\Services\ReportScreening;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The System Admin's Security page: reporters behind reports the desk closed as fake, blocking
 * the contact number and/or account behind one of those reports, the list of blocked reporters,
 * and unblocking. A blocked reporter's new reports are refused with the shelter's phone number;
 * their earlier reports are untouched.
 */
class BlockedReporterController extends Controller
{
    /**
     * Reporters with reports closed as fake, most offending first — one row per contact number
     * (or account, for reports without one), the same basis ReportScreening::history() uses.
     * `report_id` is their latest fake report, which is what store() blocks by.
     */
    public function flagged()
    {
        $fake = RescueReport::with('user:id,full_name')
            ->where('status', 'invalid')
            ->where('invalid_reason', 'fake')
            ->where(fn ($q) => $q->whereNotNull('contact_hash')->orWhereNotNull('user_id'))
            ->orderByDesc('created_at')
            ->get();

        $reporters = $fake
            ->groupBy(fn (RescueReport $r) => $r->contact_hash ? 'contact:'.$r->contact_hash : 'user:'.$r->user_id)
            ->map(function ($reports) {
                $latest = $reports->first();

                return [
                    'report_id' => $latest->id,
                    'label' => collect([
                        $latest->contact_number ? ReportScreening::maskContact($latest->contact_number) : null,
                        $latest->user?->full_name ? "account: {$latest->user->full_name}" : null,
                    ])->filter()->implode(' · ') ?: 'Reporter of report #'.$latest->id,
                    'fake_reports' => $reports->count(),
                    'last_report_at' => $latest->created_at,
                    'blocked' => (bool) ReportScreening::blockFor($latest->contact_hash, $latest->user_id),
                ];
            })
            ->sortByDesc('fake_reports')
            ->values();

        return response()->json(['reporters' => $reporters]);
    }

    public function index()
    {
        $blocked = BlockedReporter::with('blocker:id,full_name')->orderByDesc('id')->get();

        return response()->json([
            'blocked' => $blocked->map(fn (BlockedReporter $b) => [
                'id' => $b->id,
                'label' => $b->label,
                'reason' => $b->reason,
                'report_id' => $b->report_id,
                'blocked_by' => $b->blocker?->full_name,
                'created_at' => $b->created_at,
            ])->values(),
        ]);
    }

    public function store(Request $request, RescueReport $report)
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        if (! $report->contact_hash && ! $report->user_id) {
            return response()->json(['message' => 'This report has no contact number or account to block.'], 422);
        }
        if (ReportScreening::blockFor($report->contact_hash, $report->user_id)) {
            return response()->json(['message' => 'This reporter is already blocked.'], 409);
        }

        $label = collect([
            $report->contact_number ? ReportScreening::maskContact($report->contact_number) : null,
            $report->user?->full_name ? "account: {$report->user->full_name}" : null,
        ])->filter()->implode(' · ');

        $blocked = BlockedReporter::create([
            'contact_hash' => $report->contact_hash,
            'user_id' => $report->user_id,
            'label' => $label ?: 'Reporter of report #'.$report->id,
            'reason' => $validator->validated()['reason'] ?? null,
            'report_id' => $report->id,
            'blocked_by' => $request->user()->id,
        ]);

        return response()->json(['blocked' => ['id' => $blocked->id, 'label' => $blocked->label]], 201);
    }

    public function destroy(BlockedReporter $blockedReporter)
    {
        $blockedReporter->delete();

        return response()->json(['message' => 'Reporter unblocked. Their new reports will be accepted again.']);
    }
}
