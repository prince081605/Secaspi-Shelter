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
        'hours_rendered',
        'performance_notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tasks()
    {
        return $this->hasMany(VolunteerTask::class, 'volunteer_id');
    }
}
