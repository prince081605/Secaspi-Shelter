<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visitation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'requested_date',
        'time_slot',
        'num_visitors',
        'notes',
        'status',
        'admin_notes',
        'read_at',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
