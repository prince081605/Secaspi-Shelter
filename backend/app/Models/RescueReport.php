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
        'admin_notes',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];
}
