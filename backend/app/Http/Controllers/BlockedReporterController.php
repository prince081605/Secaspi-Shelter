<?php

namespace App\Http\Controllers;

use App\Models\BlockedReporter;
use App\Models\RescueReport;
use App\Services\ReportScreening;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin-only: blocking the contact number and/or account behind a rescue report (after fake
 * reports), the list of blocked reporters, and unblocking. A blocked reporter's new reports are
 * refused with the shelter's phone number; their earlier reports are untouched.
 */
class BlockedReporterController extends Controller
{
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
