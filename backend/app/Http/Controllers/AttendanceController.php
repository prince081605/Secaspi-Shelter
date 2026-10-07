<?php

namespace App\Http\Controllers;

use App\Models\Volunteer;
use App\Models\VolunteerAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Volunteer & staff attendance. People clock themselves in and out; staff can see the log and
 * who is on duty; admins can add, correct or remove records. Every change to a closed shift
 * recalculates the person's hours rendered (see Volunteer::recalculateHours).
 */
class AttendanceController extends Controller
{
    /** Longest shift an admin may record in one entry. */
    private const MAX_RECORD_MINUTES = 24 * 60;

    /** How far ahead of the server's clock an admin-entered time may be (device clock drift). */
    private const CLOCK_SKEW_MINUTES = 5;

    // ---- Self-service -------------------------------------------------------------------------

    /** The signed-in person's clock: open shift, recent shifts, and hour totals. */
    public function me(Request $request)
    {
        $volunteer = $this->ownVolunteer($request);
        if (! $volunteer) {
            return response()->json(['attendance' => null]);
        }

        return response()->json(['attendance' => $this->summaryFor($volunteer)]);
    }

    public function clockIn(Request $request)
    {
        $volunteer = $this->ownVolunteer($request);
        if (! $volunteer) {
            return response()->json(['message' => 'Only volunteers and staff can clock in.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        // Locked so a double-tap can't open two shifts at once.
        $created = DB::transaction(function () use ($volunteer, $validator) {
            Volunteer::whereKey($volunteer->id)->lockForUpdate()->first();
            if ($volunteer->attendances()->whereNull('time_out')->exists()) {
                return false;
            }

            return $volunteer->attendances()->create([
                'time_in' => now(),
                'notes' => $validator->validated()['notes'] ?? null,
            ]);
        });

        if (! $created) {
            return response()->json(['message' => "You're already clocked in."], 409);
        }

        return response()->json(['attendance' => $this->summaryFor($volunteer->fresh())], 201);
    }

    public function clockOut(Request $request)
    {
        $volunteer = $this->ownVolunteer($request);
        if (! $volunteer) {
            return response()->json(['message' => 'Only volunteers and staff can clock out.'], 403);
        }

        $open = $volunteer->attendances()->whereNull('time_out')->latest('time_in')->first();
        if (! $open) {
            return response()->json(['message' => "You're not clocked in."], 409);
        }

        $now = now();
        $open->update([
            'time_out' => $now,
            // Capped: a shift left running because someone forgot to clock out shouldn't count
            // for the whole night. An admin can correct the record if it was genuinely longer.
            'minutes' => min(VolunteerAttendance::minutesBetween($open->time_in, $now), VolunteerAttendance::MAX_SELF_MINUTES),
        ]);
        $volunteer->recalculateHours();

        return response()->json(['attendance' => $this->summaryFor($volunteer->fresh())]);
    }

    // ---- Admin --------------------------------------------------------------------------------

    /**
     * The attendance log, newest first, plus who is on duty right now. Filters: from / to (dates,
     * shelter time), volunteer_id, type (volunteer|staff).
     */
    public function adminIndex(Request $request)
    {
        $query = VolunteerAttendance::query()->with(['volunteer.user', 'recorder']);

        [$from, $to] = $this->rangeInUtc($request);
        if ($from) {
            $query->where('time_in', '>=', $from);
        }
        if ($to) {
            $query->where('time_in', '<', $to);
        }
        if ($volunteerId = $request->query('volunteer_id')) {
            $query->where('volunteer_id', $volunteerId);
        }
        if (in_array($type = $request->query('type'), ['volunteer', 'staff'], true)) {
            $query->whereHas('volunteer', fn ($q) => $q->where('type', $type));
        }

        $records = $query->orderByDesc('time_in')->paginate(30)->withQueryString();
        $records->getCollection()->transform(fn (VolunteerAttendance $a) => $this->toItem($a));

        $onDuty = VolunteerAttendance::with('volunteer.user')->whereNull('time_out')->orderBy('time_in')->get()
            ->map(fn (VolunteerAttendance $a) => $this->toItem($a))->values();

        return response()->json([
            ...$records->toArray(),
            'on_duty' => $onDuty,
        ]);
    }

    /** Record a shift for someone (e.g. they forgot to clock in, or have no phone with them). */
    public function adminStore(Request $request, Volunteer $volunteer)
    {
        $validator = $this->recordValidator($request);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();

        if (empty($data['time_out']) && $volunteer->attendances()->whereNull('time_out')->exists()) {
            return response()->json(['message' => 'This person is already clocked in.'], 409);
        }

        $record = $volunteer->attendances()->make($this->recordAttributes($data));
        $record->forceFill(['recorded_by' => $request->user()->id])->save();
        $volunteer->recalculateHours();

        return response()->json(['attendance' => $this->toItem($record->load(['volunteer.user', 'recorder']))], 201);
    }

    /** Correct a record's times or note. */
    public function adminUpdate(Request $request, VolunteerAttendance $attendance)
    {
        $validator = $this->recordValidator($request);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();

        if (empty($data['time_out'])
            && VolunteerAttendance::where('volunteer_id', $attendance->volunteer_id)->whereNull('time_out')->whereKeyNot($attendance->id)->exists()) {
            return response()->json(['message' => 'This person already has an open shift.'], 409);
        }

        $attendance->fill($this->recordAttributes($data));
        $attendance->forceFill(['recorded_by' => $request->user()->id])->save();
        $attendance->volunteer->recalculateHours();

        return response()->json(['attendance' => $this->toItem($attendance->load(['volunteer.user', 'recorder']))]);
    }

    /**
     * Close someone's open shift at the server's current time — "Time out" in the log.
     *
     * Stamped here rather than sent by the page: the admin's device clock is never exactly the
     * server's, and a time-out a few seconds behind it failed `after:time_in` on a fresh shift
     * (or `before_or_equal:now` when ahead). Capped like a self clock-out, since this is usually
     * someone who forgot; an exact longer shift can still be set with Edit.
     */
    public function adminTimeOut(Request $request, VolunteerAttendance $attendance)
    {
        if (! $attendance->isOpen()) {
            return response()->json(['message' => 'This shift has already been timed out.'], 409);
        }

        $now = now();
        $attendance->fill([
            'time_out' => $now,
            'minutes' => min(VolunteerAttendance::minutesBetween($attendance->time_in, $now), VolunteerAttendance::MAX_SELF_MINUTES),
            'notes' => $attendance->notes ?: 'Timed out by admin',
        ]);
        $attendance->forceFill(['recorded_by' => $request->user()->id])->save();
        $attendance->volunteer->recalculateHours();

        return response()->json(['attendance' => $this->toItem($attendance->load(['volunteer.user', 'recorder']))]);
    }

    public function adminDestroy(VolunteerAttendance $attendance)
    {
        $volunteer = $attendance->volunteer;
        $attendance->delete();
        $volunteer?->recalculateHours();

        return response()->json(['message' => 'Attendance record deleted']);
    }

    // ---- Helpers ------------------------------------------------------------------------------

    private function ownVolunteer(Request $request): ?Volunteer
    {
        return Volunteer::where('user_id', $request->user()->id)->first();
    }

    private function recordValidator(Request $request)
    {
        // "Not in the future" is judged by the server's clock, but the times come from the admin's
        // device, which is never set exactly the same. A few minutes' grace stops "now" typed on a
        // slightly-fast device from being rejected as a future time.
        $latest = now()->addMinutes(self::CLOCK_SKEW_MINUTES)->toIso8601String();

        return Validator::make($request->all(), [
            'time_in' => ['required', 'date', "before_or_equal:{$latest}"],
            'time_out' => ['nullable', 'date', 'after:time_in', "before_or_equal:{$latest}"],
            'notes' => ['nullable', 'string', 'max:255'],
        ])->after(function ($validator) use ($request) {
            if ($validator->errors()->isNotEmpty() || ! $request->filled('time_out')) {
                return;
            }
            $minutes = VolunteerAttendance::minutesBetween(
                CarbonImmutable::parse($request->input('time_in')),
                CarbonImmutable::parse($request->input('time_out')),
            );
            if ($minutes > self::MAX_RECORD_MINUTES) {
                $validator->errors()->add('time_out', 'A single shift can be at most 24 hours.');
            }
        });
    }

    private function recordAttributes(array $data): array
    {
        $in = CarbonImmutable::parse($data['time_in']);
        $out = empty($data['time_out']) ? null : CarbonImmutable::parse($data['time_out']);

        return [
            'time_in' => $in,
            'time_out' => $out,
            'minutes' => $out ? VolunteerAttendance::minutesBetween($in, $out) : null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /** from / to are shelter-local dates; turn them into a [from, to) window in UTC. */
    private function rangeInUtc(Request $request): array
    {
        $tz = VolunteerAttendance::TIMEZONE;
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from'), $tz)->startOfDay()->utc() : null;
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to'), $tz)->addDay()->startOfDay()->utc() : null;

        return [$from, $to];
    }

    private function summaryFor(Volunteer $volunteer): array
    {
        $tz = VolunteerAttendance::TIMEZONE;
        $weekStart = now($tz)->startOfWeek()->utc();
        $monthStart = now($tz)->startOfMonth()->utc();

        $open = $volunteer->attendances()->whereNull('time_out')->latest('time_in')->first();
        $recent = $volunteer->attendances()->with('recorder')->orderByDesc('time_in')->limit(10)->get();
        $closed = $volunteer->attendances()->whereNotNull('time_out');

        return [
            'on_duty' => (bool) $open,
            'open_since' => $open?->time_in,
            'hours_rendered' => (float) $volunteer->hours_rendered,
            'week_hours' => round(((int) (clone $closed)->where('time_in', '>=', $weekStart)->sum('minutes')) / 60, 2),
            'month_hours' => round(((int) (clone $closed)->where('time_in', '>=', $monthStart)->sum('minutes')) / 60, 2),
            'max_self_hours' => VolunteerAttendance::MAX_SELF_MINUTES / 60,
            'recent' => $recent->map(fn (VolunteerAttendance $a) => $this->toItem($a, false))->values(),
        ];
    }

    private function toItem(VolunteerAttendance $a, bool $withPerson = true): array
    {
        $item = [
            'id' => $a->id,
            'time_in' => $a->time_in,
            'time_out' => $a->time_out,
            'minutes' => $a->minutes,
            'hours' => $a->minutes === null ? null : round($a->minutes / 60, 2),
            'notes' => $a->notes,
            'recorded_by' => $a->recorder?->full_name,
        ];

        if ($withPerson) {
            $item['volunteer'] = $a->volunteer ? [
                'id' => $a->volunteer->id,
                'type' => $a->volunteer->type,
                'full_name' => $a->volunteer->user?->full_name,
            ] : null;
        }

        return $item;
    }
}
