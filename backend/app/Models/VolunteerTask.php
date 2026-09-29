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
        'proof_path',
        'proof_note',
        'proof_submitted_at',
    ];

    protected $casts = [
        'proof_submitted_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function volunteer()
    {
        return $this->belongsTo(Volunteer::class, 'volunteer_id');
    }

    // The admin who verified the task complete. Not fillable: it is set from the signed-in
    // admin by the controller, never taken from the request.
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
