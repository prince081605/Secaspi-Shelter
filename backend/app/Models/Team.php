<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A team of personnel (staff and volunteers) — e.g. a rescue team or the feeding crew. Rescue
 * reports can be assigned to it, and tasks given to all its members at once. Archived rather
 * than deleted, so the rescues and tasks that name it keep their history.
 */
class Team extends Model
{
    protected $fillable = ['name', 'purpose', 'leader_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function members()
    {
        return $this->belongsToMany(Volunteer::class, 'team_members', 'team_id', 'volunteer_id')->orderBy('team_members.id');
    }

    /** Always one of the members (or nobody). */
    public function leader()
    {
        return $this->belongsTo(Volunteer::class, 'leader_id');
    }

    public function rescueReports()
    {
        return $this->hasMany(RescueReport::class, 'team_id');
    }

    public function tasks()
    {
        return $this->hasMany(VolunteerTask::class, 'team_id');
    }
}
