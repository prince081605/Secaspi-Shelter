<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A named area of the shelter (e.g. "Kennel 1", "House 2") an animal can be kept in. */
class ShelterLocation extends Model
{
    protected $fillable = ['name'];

    public function animals()
    {
        return $this->hasMany(Animal::class, 'current_location_id');
    }
}
