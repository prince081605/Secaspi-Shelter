<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A scheduled post-adoption check-in on how an adopted animal is settling in. See App\Services\PostAdoption. */
class AdoptionFollowUp extends Model
{
    public const UPDATED_AT = null;

    /** How staff reached the adopter for a check-in. */
    public const CONTACT_METHODS = ['call', 'text', 'visit', 'message', 'adopter_update', 'other'];

    /** How the animal is doing, as staff judge it. */
    public const WELLBEING = ['doing_well', 'needs_support', 'concern'];

    protected $fillable = [
        'adoption_application_id', 'animal_id', 'label', 'due_date', 'status',
        'contact_method', 'wellbeing', 'notes', 'recorded_by', 'completed_at', 'adopter_notified_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'adopter_notified_at' => 'datetime',
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

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
