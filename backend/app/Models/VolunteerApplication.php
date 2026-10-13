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
        'preferred_categories',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        // What the applicant would like to help with ('volunteer_category' keys).
        'preferred_categories' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
