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

    /**
     * Human-readable form of the stored status enum, for display to the public.
     *
     * Static (not an accessor) so it can also be used where animals are read via the query
     * builder rather than the model — PublicHomeController::featuredAnimals() selects raw
     * rows for speed and gets stdClass back, which no accessor would reach. Keeping the one
     * copy here is what stops the landing page and the adoption list drifting apart, which
     * they had: one said "Available for adoption", the other showed the raw "available".
     */
    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'available' => 'Available for adoption',
            'adopted' => 'Adopted',
            'fostered' => 'In foster care',
            'medical' => 'Medical recovery',
            'quarantine' => 'In quarantine',
            'archived' => 'Archived',
            default => $status ? ucfirst($status) : 'Unknown',
        };
    }

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
