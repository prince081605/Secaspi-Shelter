<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MarksAdminRead;
use App\Models\Visitation;
use App\Notifications\VisitationStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VisitationController extends Controller
{
    use MarksAdminRead;

    private const TIME_SLOTS = ['morning', 'afternoon', 'evening'];

    private const STATUSES = ['pending', 'approved', 'rejected', 'completed'];

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Must be a real future date, and not more than 30 days out.
            'requested_date' => ['required', 'date', 'after:today', 'before_or_equal:'.now()->addDays(30)->toDateString()],
            'time_slot' => ['required', 'in:'.implode(',', self::TIME_SLOTS)],
            'num_visitors' => ['required', 'integer', 'min:1', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // Block booking the exact same schedule (date + time slot) twice within a 30-day
        // window, regardless of the earlier request's outcome. They can pick a different
        // date or slot anytime.
        $validated = $validator->validated();
        $dupe = Visitation::where('user_id', $user->id)
            ->where('requested_date', $validated['requested_date'])
            ->where('time_slot', $validated['time_slot'])
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        if ($dupe) {
            return response()->json([
                'message' => 'You already have a visit request for this date and time slot. Please pick a different time.',
            ], 409);
        }

        try {
            $visitation = Visitation::create([
                'user_id' => $user->id,
                'status' => 'pending',
                ...$validator->validated(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create visitation request', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to submit visit request. Please try again.'], 500);
        }

        return response()->json(['visitation' => $this->toItem($visitation)], 201);
    }

    public function index(Request $request)
    {
        $visitations = Visitation::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'visitations' => $visitations->map(fn (Visitation $v) => $this->toItem($v))->values(),
        ]);
    }

    public function adminIndex(Request $request)
    {
        $query = Visitation::query()->with('user');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $visitations = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $visitations->getCollection()->transform(fn (Visitation $v) => $this->toAdminItem($v));

        return response()->json($visitations);
    }

    public function adminUpdate(Request $request, Visitation $visitation)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', 'in:'.implode(',', self::STATUSES)],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (is_null($visitation->read_at)) {
            $data['read_at'] = now();
        }

        $visitation->update($data);
        $statusChanged = $visitation->wasChanged('status');

        $visitation = $visitation->fresh('user');

        if ($statusChanged && $visitation->user) {
            (new VisitationStatusChanged($visitation))->sendTo($visitation->user);
        }

        return response()->json(['visitation' => $this->toAdminItem($visitation)]);
    }

    /**
     * Marks a request read the first time an admin opens it, independent of any
     * status change — mirrors the adoption-applications pattern so the unread
     * badge clears on review even without an approve/reject.
     */
    public function adminMarkRead(Visitation $visitation)
    {
        $this->markReadOnce($visitation);

        return response()->json(['visitation' => $this->toAdminItem($visitation)]);
    }

    private function toItem(Visitation $v): array
    {
        return [
            'id' => $v->id,
            'requested_date' => optional($v->requested_date)->toDateString(),
            'time_slot' => $v->time_slot,
            'num_visitors' => $v->num_visitors,
            'notes' => $v->notes,
            'status' => $v->status,
            'admin_notes' => $v->admin_notes,
            'created_at' => $v->created_at,
        ];
    }

    private function toAdminItem(Visitation $v): array
    {
        return [
            ...$this->toItem($v),
            'read_at' => $v->read_at,
            'visitor' => $v->user ? [
                'id' => $v->user->id,
                'full_name' => $v->user->full_name,
                'email' => $v->user->email,
                'phone' => $v->user->phone,
            ] : null,
        ];
    }
}
