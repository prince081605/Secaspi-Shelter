<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An adopter asking to bring an adopted animal back to the shelter. See App\Services\PostAdoption::resolveReturn(). */
class AdoptionReturn extends Model
{
    public const UPDATED_AT = null;

    /** Where a returned animal can go: back on the adoption listing, or into care first. */
    public const ANIMAL_STATUSES = ['available', 'medical', 'quarantine'];

    protected $fillable = [
        'adoption_application_id', 'animal_id', 'user_id', 'reason', 'status', 'animal_status',
        'staff_notes', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(AdoptionApplication::class, 'adoption_application_id');
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
