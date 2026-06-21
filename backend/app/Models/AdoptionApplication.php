<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdoptionApplication extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'animal_id',
        'reference_no',
        'status',
        'full_name',
        'address',
        'occupation',
        'housing_type',
        'pet_experience',
        'reason',
        'home_visit_status',
        'home_visit_date',
        'home_visit_notes',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
