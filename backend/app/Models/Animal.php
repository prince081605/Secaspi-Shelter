<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class Animal extends Model
{
    public $timestamps = false;

    // The shelter takes in dogs and cats only. Stored lowercase (the Matchmaker filters on it);
    // animals and intakes both validate against this list.
    public const SPECIES = ['dog', 'cat'];

    public const STATUSES = ['available', 'adopted', 'fostered', 'medical', 'quarantine', 'archived'];

    public const GENDERS = ['male', 'female'];

    public const SIZES = ['small', 'medium', 'large'];

    // The behavioral issues the admin form offers as checkboxes (mirrored in AnimalsAdmin.jsx).
    // The Matchmaker and care guides key off this vocabulary, so the Excel import only accepts
    // these exact phrases.
    public const BEHAVIORAL_ISSUES = [
        'separation anxiety',
        'aggression & resource guarding',
        'dog-to-dog aggression',
        'territorial aggression',
        'fear aggression',
        'destructive chewing & digging',
        'inappropriate elimination',
        'excessive barking',
        'excessive vocalization',
        'jumping/mouthing',
        'pulling on leash',
        'excessive energy',
        'extreme shyness',
        'fear of strangers',
        'fear of loud noises',
        'post-trauma/trust issues',
        'pain-related aggression',
        'cognitive issues (senior)',
    ];

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
     * Validation rules for a new animal's own fields. Shared by the Add Animal form
     * (AnimalController::store) and the Excel import (AnimalImporter) so a spreadsheet row is held
     * to exactly the same rules as a hand-entered animal.
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'species' => ['required', Rule::in(self::SPECIES)],
            'breed' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'min:0'],
            'gender' => ['nullable', Rule::in(self::GENDERS)],
            'size' => ['nullable', Rule::in(self::SIZES)],
            // The column is decimal(8,2); anything larger is a typo the database would reject.
            'weight' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'rescue_story' => ['nullable', 'string'],
            'behavioral_assessment' => ['nullable', 'array'],
            'behavioral_assessment.*' => ['string', 'max:100'],
        ];
    }

    /**
     * An existing animal with the same name and species (case-insensitive, any status) — what
     * counts as an accidental duplicate, both in the Add Animal form and in an Excel import.
     */
    public static function findDuplicate(string $name, string $species): ?self
    {
        return self::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->whereRaw('LOWER(species) = ?', [mb_strtolower(trim($species))])
            ->first();
    }

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

    // current_location_id is deliberately not fillable: it only changes through
    // AnimalController::move(), so every change is also written to the location log.
    public function currentLocation()
    {
        return $this->belongsTo(ShelterLocation::class, 'current_location_id');
    }

    public function locationLogs()
    {
        return $this->hasMany(AnimalLocationLog::class, 'animal_id');
    }
}
