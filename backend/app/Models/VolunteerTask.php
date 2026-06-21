<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VolunteerTask extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'volunteer_id',
        'task_name',
        'status',
        'assigned_date',
    ];

    public function volunteer()
    {
        return $this->belongsTo(Volunteer::class, 'volunteer_id');
    }
}
