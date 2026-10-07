<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Volunteer extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'availability',
        'performance_notes',
    ];

    protected $casts = [
        'hours_rendered' => 'float',
        'hours_adjustment' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tasks()
    {
        return $this->hasMany(VolunteerTask::class, 'volunteer_id');
    }

    public function attendances()
    {
        return $this->hasMany(VolunteerAttendance::class, 'volunteer_id');
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_members', 'volunteer_id', 'team_id');
    }

    /** Hours backed by closed attendance records. */
    public function attendanceHours(): float
    {
        return round(((int) $this->attendances()->whereNotNull('time_out')->sum('minutes')) / 60, 2);
    }

    /**
     * hours_rendered is derived: the hand-entered adjustment plus every closed shift. Called
     * whenever attendance or the adjustment changes, so the stored total (which the leaderboard,
     * reports and certificates read) never drifts from the log.
     */
    public function recalculateHours(): void
    {
        $this->forceFill([
            'hours_rendered' => max(0, round((float) $this->hours_adjustment + $this->attendanceHours(), 2)),
        ])->save();
    }

    /**
     * An admin sets the total directly (e.g. to credit work done before attendance was logged):
     * whatever attendance doesn't cover becomes the adjustment.
     */
    public function setTotalHours(float $total): void
    {
        $this->forceFill(['hours_adjustment' => round($total - $this->attendanceHours(), 2)])->save();
        $this->recalculateHours();
    }
}
