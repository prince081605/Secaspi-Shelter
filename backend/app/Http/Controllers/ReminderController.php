<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReminderController extends Controller
{
    /**
     * Upcoming + overdue reminders for the admin dashboard. This is the reliable
     * surface (no cron required): it always reflects current due dates. Returns
     * everything still open (pending/sent) up to `days` ahead, plus anything overdue,
     * with overdue items first and soonest dates on top.
     */
    public function adminIndex(Request $request)
    {
        $days = (int) ($request->query('days', 30));
        $horizon = now()->startOfDay()->addDays(max(1, $days));

        $reminders = Reminder::query()
            ->with('animal:id,name,species')
            ->whereIn('status', ['pending', 'sent'])
            ->whereDate('reminder_date', '<=', $horizon)
            ->orderBy('reminder_date')
            ->get();

        $today = now()->startOfDay();

        return response()->json([
            'reminders' => $reminders->map(function (Reminder $r) use ($today) {
                $date = $r->reminder_date;
                return [
                    'id' => $r->id,
                    'title' => $r->title,
                    'reminder_date' => optional($date)->toDateString(),
                    'status' => $r->status,
                    'is_overdue' => $date ? $date->lt($today) : false,
                    'animal' => $r->animal ? [
                        'id' => $r->animal->id,
                        'name' => $r->animal->name,
                        'species' => $r->animal->species,
                    ] : null,
                ];
            })->values(),
            'overdue_count' => $reminders->filter(fn (Reminder $r) => $r->reminder_date && $r->reminder_date->lt($today))->count(),
        ]);
    }

    public function adminUpdate(Request $request, Reminder $reminder)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'in:pending,sent,completed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $reminder->update($validator->validated());

        return response()->json(['reminder' => $reminder]);
    }
}
