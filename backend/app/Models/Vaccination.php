<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vaccination extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'animal_id',
        'vaccine_name',
        'date_given',
        'next_due',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }
}
