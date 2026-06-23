<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Animal extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'species',
        'breed',
        'age',
        'gender',
        'size',
        'weight',
        'status',
        'rescue_story',
        'qr_code_path',
        'behavioral_assessment',
    ];

    protected $casts = [
        'behavioral_assessment' => 'array',
    ];

    public function photos()
    {
        return $this->hasMany(AnimalPhoto::class, 'animal_id');
    }

    public function mainPhoto()
    {
        return $this->hasOne(AnimalPhoto::class, 'animal_id')
            ->orderByDesc('is_main')
            ->orderBy('id');
    }

    public function medicalRecords()
    {
        return $this->hasMany(MedicalRecord::class, 'animal_id');
    }

    public function vaccinations()
    {
        return $this->hasMany(Vaccination::class, 'animal_id');
    }

    public function adoptionApplications()
    {
        return $this->hasMany(AdoptionApplication::class, 'animal_id');
    }

    public function fosterApplications()
    {
        return $this->hasMany(FosterApplication::class, 'animal_id');
    }
}
