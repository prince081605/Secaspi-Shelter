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
        'contact_number',
        'address',
        'occupation',
        'housing_type',
        'pet_experience',
        'reason',
        'valid_id_type',
        'valid_id_path',
        'home_visit_status',
        'home_visit_date',
        'home_visit_notes',
        'read_at',
        'completed_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ---- Post-adoption (App\Services\PostAdoption), from the moment the adoption is completed ----

    public function followUps()
    {
        return $this->hasMany(AdoptionFollowUp::class, 'adoption_application_id')->orderBy('due_date');
    }

    public function updates()
    {
        return $this->hasMany(AdoptionUpdate::class, 'adoption_application_id')->orderByDesc('id');
    }

    public function returns()
    {
        return $this->hasMany(AdoptionReturn::class, 'adoption_application_id')->orderByDesc('id');
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
