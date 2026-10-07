<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RescueReport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reporter_name',
        'contact_number',
        'location',
        'latitude',
        'longitude',
        'description',
        'urgency',
        'status',
        'photo_url',
        'created_at',
        'assigned_to',
        'team_id',
        'admin_notes',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    /** The team sent to handle it. Older reports may instead carry free text in `assigned_to`. */
    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
