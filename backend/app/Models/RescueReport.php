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
        // Screening (App\Services\ReportScreening): who sent it, and staff closing it as invalid.
        'contact_hash',
        'ip_hash',
        'user_id',
        'possible_duplicate_of_id',
        'invalid_reason',
        'invalid_note',
        'duplicate_of_id',
    ];

    protected $hidden = ['contact_hash', 'ip_hash'];

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

    /** The account it was sent from, when the reporter was logged in. */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** An open report nearby, found when this one was sent. */
    public function possibleDuplicate()
    {
        return $this->belongsTo(self::class, 'possible_duplicate_of_id');
    }

    /** The report staff said this one repeats, when they closed it as a duplicate. */
    public function duplicateOf()
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }
}
