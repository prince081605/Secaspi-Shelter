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
        'description',
        'urgency',
        'status',
        'photo_url',
        'created_at',
        'assigned_to',
        'admin_notes',
    ];
}
