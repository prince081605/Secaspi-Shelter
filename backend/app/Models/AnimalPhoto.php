<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnimalPhoto extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'animal_id',
        'photo_url',
        'is_main',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }
}
