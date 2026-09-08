<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VolunteerApplication extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'availability',
        'experience',
        'reason',
        'valid_id_type',
        'valid_id_path',
        'status',
        'admin_notes',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
