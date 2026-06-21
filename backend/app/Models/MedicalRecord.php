<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'animal_id',
        'type',
        'description',
        'vet_name',
        'cost',
        'record_date',
        'notes',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }
}
