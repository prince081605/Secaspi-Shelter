<?php

namespace App\Http\Controllers;

use App\Models\RescueReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RescueReportController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reporter_name'   => ['nullable', 'string', 'max:150'],
            'contact_number'  => ['nullable', 'string', 'max:20'],
            'location'        => ['required', 'string'],
            'description'     => ['nullable', 'string'],
            'urgency'         => ['required', 'in:low,medium,high,critical'],
            'photo'           => ['nullable', 'image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $photoPath = $request->hasFile('photo')
                ? $request->file('photo')->store('rescue-reports', 'public')
                : null;

            $report = RescueReport::create([
                'reporter_name'  => $request->input('reporter_name'),
                'contact_number' => $request->input('contact_number'),
                'location'       => $request->input('location'),
                'description'    => $request->input('description'),
                'urgency'        => $request->input('urgency'),
                'status'         => 'pending',
                'photo_url'      => $photoPath,
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record rescue report', [
                'location'  => $request->input('location'),
                'urgency'   => $request->input('urgency'),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to submit report. Please try again.'], 500);
        }

        return response()->json([
            'report' => [
                'id'     => $report->id,
                'status' => $report->status,
            ],
        ], 201);
    }

    public function index(Request $request)
    {
        $query = RescueReport::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $reports = $query->orderByDesc('id')->paginate(12)->withQueryString();

        return response()->json($reports);
    }

    public function updateStatus(Request $request, RescueReport $report)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', 'in:pending,assigned,in_progress,resolved'],
            'assigned_to' => ['nullable', 'string', 'max:150'],
            'admin_notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $report->update($validator->validated());
        } catch (\Throwable $e) {
            Log::error('Failed to update rescue report', [
                'report_id' => $report->id,
                'payload'   => $validator->validated(),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to update report. Please try again.'], 500);
        }

        return response()->json(['report' => $report]);
    }
}
