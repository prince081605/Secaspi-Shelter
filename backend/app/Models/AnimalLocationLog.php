<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One move of an animal between shelter areas: from, to, who, when, and how it was recorded. */
class AnimalLocationLog extends Model
{
    public const UPDATED_AT = null;

    public const SOURCES = ['qr_page', 'admin'];

    protected $fillable = ['animal_id', 'from_location_id', 'to_location_id', 'source', 'note'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }

    public function fromLocation()
    {
        return $this->belongsTo(ShelterLocation::class, 'from_location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(ShelterLocation::class, 'to_location_id');
    }

    public function mover()
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
