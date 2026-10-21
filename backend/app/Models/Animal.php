<?php
namespace App\Models;

use App\Rules\NotInFuture;
use App\Rules\ValidAge;
use App\Support\AnimalAge;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class Animal extends Model
{
    public $timestamps = false;

    // The shelter takes in dogs and cats only. Stored lowercase (the Matchmaker filters on it);
    // animals and intakes both validate against this list.
    public const SPECIES = ['dog', 'cat'];

    // 'deceased' is set from Post-adoption when an adopted animal dies (or here, for one that died in care).
    public const STATUSES = ['available', 'adopted', 'fostered', 'medical', 'quarantine', 'archived', 'deceased'];

    public const GENDERS = ['male', 'female'];

    public const SIZES = ['small', 'medium', 'large'];

    // Breeds and behavioral issues are admin-managed lists: see CategoryOption.

    protected $fillable = [
        'name',
        'species',
        'breed',
        // Estimated. The age is worked out from it whenever it's shown, so a puppy grows up on
        // its own (see App\Support\AnimalAge); staff enter an age, which becomes this date.
        'birth_date',
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
        'birth_date' => 'date',
    ];

    /**
     * How an age is entered: a whole number with its unit ("3 months", "6 weeks", "2 years"), or
     * an estimated birth date. Either becomes birth_date (ageToBirthDate()). Shared by the Add
     * Animal form, editing, and the Excel import.
     */
    public static function ageRules(): array
    {
        return [
            'age_value' => ['bail', 'nullable', 'integer', 'min:0', new ValidAge],
            'age_unit' => ['nullable', 'required_with:age_value', Rule::in(AnimalAge::UNITS)],
            'birth_date' => ['nullable', 'date', new NotInFuture, 'after:'.AnimalAge::today()->subYears(AnimalAge::MAX_YEARS)->toDateString()],
        ];
    }

    /**
     * Validated input with the entered age turned into birth_date: an explicit birth date wins,
     * otherwise age_value + age_unit (an empty age clears it). Untouched when neither was sent.
     */
    public static function ageToBirthDate(array $data): array
    {
        if (! empty($data['birth_date'])) {
            // keep the date as given
        } elseif (array_key_exists('age_value', $data)) {
            $data['birth_date'] = $data['age_value'] === null || $data['age_value'] === ''
                ? null
                : AnimalAge::birthDate((int) $data['age_value'], $data['age_unit'])->toDateString();
        }
        unset($data['age_value'], $data['age_unit']);

        return $data;
    }

    /** Whole years old today (null when unknown) — for anything that still thinks in years. */
    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birth_date ? intdiv(AnimalAge::months($this->birth_date), 12) : null);
    }

    /** "8 weeks", "5 months", "3 years" — or null when the age isn't known. */
    public function ageLabel(): ?string
    {
        return AnimalAge::label($this->birth_date);
    }

    /** The age as staff would type it (value + unit), to fill the edit form. */
    public function ageParts(): ?array
    {
        return AnimalAge::parts($this->birth_date);
    }

    /** puppy / adult / senior, for matching care guides. */
    public function lifeStage(): ?string
    {
        return AnimalAge::lifeStage($this->birth_date);
    }

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
            ...self::ageRules(),
            'gender' => ['nullable', Rule::in(self::GENDERS)],
            'size' => ['nullable', Rule::in(self::SIZES)],
            // Kilograms. The heaviest breeds top out near 100 kg, so anything past 200 is a typo.
            'weight' => ['nullable', 'numeric', 'min:0', 'max:200'],
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
            'deceased' => 'Deceased',
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
