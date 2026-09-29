<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One shift worked by a volunteer or staff member: when they clocked in, when they clocked out,
 * and the minutes that counts for.
 */
class VolunteerAttendance extends Model
{
    /** The shelter's clock. The app runs on UTC; "which day" questions are answered in Manila. */
    public const TIMEZONE = 'Asia/Manila';

    /**
     * A self-recorded shift counts for at most this long, so a forgotten clock-out left running
     * overnight doesn't hand someone 20 hours. An admin can correct the record either way.
     */
    public const MAX_SELF_MINUTES = 12 * 60;

    protected $fillable = [
        'volunteer_id',
        'time_in',
        'time_out',
        'minutes',
        'notes',
    ];

    protected $casts = [
        'time_in' => 'datetime',
        'time_out' => 'datetime',
        'minutes' => 'integer',
    ];

    public function volunteer()
    {
        return $this->belongsTo(Volunteer::class, 'volunteer_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function minutesBetween(CarbonInterface $in, CarbonInterface $out): int
    {
        return max(0, (int) floor($in->diffInSeconds($out, false) / 60));
    }

    public function isOpen(): bool
    {
        return $this->time_out === null;
    }
}
