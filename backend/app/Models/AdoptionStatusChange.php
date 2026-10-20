<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One entry in an adoption's post-adoption timeline. See App\Services\PostAdoption::changeStatus(). */
class AdoptionStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'adoption_application_id', 'animal_id', 'from_status', 'to_status', 'happened_on',
        'animal_status', 'notes', 'changed_by',
    ];

    protected $casts = [
        'happened_on' => 'date',
        'created_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(AdoptionApplication::class, 'adoption_application_id');
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
