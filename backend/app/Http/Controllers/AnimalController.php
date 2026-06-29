<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalPhoto;
use App\Models\CareGuide;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AnimalController extends Controller
{
    private const STATUSES = ['available', 'adopted', 'fostered', 'medical', 'quarantine', 'archived'];

    public function index(Request $request)
    {
        // Public adoption browsing should never surface animals that are already adopted
        // or archived — they aren't available to apply for.
        $query = Animal::query()->whereNotIn('status', ['archived', 'adopted']);

        if ($q = $request->query('q')) {
            $this->applySearch($query, $q);
        }

        foreach (['species', 'gender', 'size', 'status'] as $field) {
            if ($value = $request->query($field)) {
                $query->where($field, $value);
            }
        }

        $animals = $query->with('mainPhoto')
            ->orderByRaw("CASE WHEN status = 'available' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        $animals->getCollection()->transform(function (Animal $animal) {
            return $this->toListItem($animal);
        });

        return response()->json($animals);
    }

    public function adminIndex(Request $request)
    {
        $query = Animal::query();

        if ($q = $request->query('q')) {
            $this->applySearch($query, $q);
        }

        foreach (['species', 'gender', 'size', 'status'] as $field) {
            if ($value = $request->query($field)) {
                $query->where($field, $value);
            }
        }

        $animals = $query->with('mainPhoto')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $animals->getCollection()->transform(function (Animal $animal) {
            return $this->toListItem($animal);
        });

        return response()->json($animals);
    }

    public function adminShow(Animal $animal)
    {
        return response()->json(['animal' => $this->toAdminDetail($animal)]);
    }

    public function show(Animal $animal)
    {
        $animal->load(['photos', 'medicalRecords', 'vaccinations']);
        $qrCode = $this->ensureQrCode($animal);

        return response()->json([
            'animal' => [
                'id' => $animal->id,
                'name' => $animal->name,
                'species' => $animal->species,
                'breed' => $animal->breed,
                'age' => $animal->age,
                'gender' => $animal->gender,
                'size' => $animal->size,
                'weight' => $animal->weight,
                'status' => $animal->status,
                'rescue_story' => $animal->rescue_story,
                'behavioral_assessment' => $animal->behavioral_assessment,
                'qr_code' => $qrCode ? Storage::url($qrCode) : null,
                'photos' => $animal->photos->pluck('photo_url')
                    ->filter()
                    ->map(fn ($path) => Storage::url($path))
                    ->values(),
                // Public payload deliberately omits cost / vet_name / notes — those are internal
                // medical details, exposed only on the staff-gated admin animal view.
                'medical_records' => $animal->medicalRecords->map(fn ($m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'description' => $m->description,
                    'record_date' => $m->record_date,
                ])->values(),
                'vaccinations' => $animal->vaccinations->map(fn ($v) => [
                    'id' => $v->id,
                    'vaccine_name' => $v->vaccine_name,
                    'date_given' => $v->date_given,
                    'next_due' => $v->next_due,
                ])->values(),
                'care_guides' => $this->careGuides($animal),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeBehavioralAssessment($request);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'species' => ['required', 'string', 'max:50'],
            'breed' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'min:0'],
            'gender' => ['nullable', 'in:male,female'],
            'size' => ['nullable', 'in:small,medium,large'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'rescue_story' => ['nullable', 'string'],
            'behavioral_assessment' => ['nullable', 'array'],
            'behavioral_assessment.*' => ['string', 'max:100'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Block accidental duplicates: an animal with the same name + species (case-insensitive)
        // across any status. The admin can knowingly override by resubmitting with force=1.
        if (! $request->boolean('force')) {
            $existing = Animal::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
                ->whereRaw('LOWER(species) = ?', [mb_strtolower($data['species'])])
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => "A {$existing->species} named \"{$existing->name}\" already exists.",
                    'existing_animal' => $this->toListItem($existing),
                ], 409);
            }
        }

        $photos = $data['photos'] ?? null;
        unset($data['photos']);
        $data['status'] = $data['status'] ?? 'available';

        $animal = Animal::create($data);

        if ($photos) {
            foreach ($photos as $i => $file) {
                $animal->photos()->create([
                    'photo_url' => $file->store('animals'),
                    'is_main' => $i === 0,
                ]);
            }
        }

        return response()->json(['animal' => $this->toAdminDetail($animal)], 201);
    }

    public function update(Request $request, Animal $animal)
    {
        $this->normalizeBehavioralAssessment($request);

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:100'],
            'species' => ['sometimes', 'string', 'max:50'],
            'breed' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'min:0'],
            'gender' => ['nullable', 'in:male,female'],
            'size' => ['nullable', 'in:small,medium,large'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'rescue_story' => ['nullable', 'string'],
            'behavioral_assessment' => ['nullable', 'array'],
            'behavioral_assessment.*' => ['string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $animal->update($validator->validated());

        return response()->json(['animal' => $this->toAdminDetail($animal)]);
    }

    /**
     * behavioral_assessment can arrive two ways: a real array (JSON request body,
     * used by the edit form) or a JSON-encoded string (multipart/FormData, used by
     * the create form, which can't send a native array). Coerce the string form to
     * an array up front so a single `array` validation rule covers both.
     */
    private function normalizeBehavioralAssessment(Request $request): void
    {
        $value = $request->input('behavioral_assessment');

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $request->merge([
                'behavioral_assessment' => json_last_error() === JSON_ERROR_NONE ? $decoded : null,
            ]);
        }
    }

    public function archive(Animal $animal)
    {
        $animal->update(['status' => 'archived']);

        return response()->json(['animal' => $this->toAdminDetail($animal)]);
    }

    public function destroy(Animal $animal)
    {
        // The frontend gates this behind a "type DELETE to confirm" modal, so once the
        // request reaches here we cascade for real: medical history, vaccinations, and
        // applications tied to this animal are removed along with it, not just blocked.
        $animal->medicalRecords()->delete();
        $animal->vaccinations()->delete();
        $animal->adoptionApplications()->delete();
        $animal->fosterApplications()->delete();

        foreach ($animal->photos as $photo) {
            if ($photo->photo_url) {
                Storage::delete($photo->photo_url);
            }
        }
        $animal->photos()->delete();
        $animal->delete();

        return response()->json(['message' => 'Animal deleted']);
    }

    public function addPhotos(Request $request, Animal $animal)
    {
        $validator = Validator::make($request->all(), [
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $hasMain = $animal->photos()->where('is_main', true)->exists();
        $created = [];

        foreach ($request->file('photos') as $i => $file) {
            $created[] = $animal->photos()->create([
                'photo_url' => $file->store('animals'),
                'is_main' => ! $hasMain && $i === 0,
            ]);
        }

        return response()->json(['photos' => $created], 201);
    }

    public function destroyPhoto(Animal $animal, AnimalPhoto $photo)
    {
        if ($photo->animal_id !== $animal->id) {
            return response()->json(['message' => 'Photo not found for this animal'], 404);
        }

        if ($photo->photo_url) {
            Storage::delete($photo->photo_url);
        }
        $photo->delete();

        return response()->json(['message' => 'Photo deleted']);
    }

    /**
     * Generate this animal's QR code (linking to its public adoption page) the first time it's
     * needed, and reuse the stored file afterwards. Covers both brand-new animals (generated right
     * after creation, via toAdminDetail()) and animals that already existed before this feature
     * shipped (generated lazily the first time their detail page is viewed) — no backfill script.
     */
    /**
     * Case-insensitive regardless of the DB's collation: LIKE alone is only
     * case-insensitive if the column happens to use a *_ci collation, so this
     * lowercases both sides explicitly instead of relying on that.
     */
    private function applySearch($query, string $q): void
    {
        $needle = '%'.mb_strtolower($q).'%';
        $query->where(function ($w) use ($needle) {
            $w->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(species) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(breed) LIKE ?', [$needle]);
        });
    }

    private function ensureQrCode(Animal $animal): ?string
    {
        if ($animal->qr_code_path && Storage::exists($animal->qr_code_path)) {
            return $animal->qr_code_path;
        }

        $url = rtrim(config('app.frontend_url'), '/').'/adopt/'.$animal->id;

        $result = (new Builder(
            writer: new SvgWriter,
            data: $url,
            size: 300,
            margin: 10,
        ))->build();

        $path = "qr-codes/{$animal->id}.svg";
        Storage::put($path, $result->getString());
        $animal->update(['qr_code_path' => $path]);

        return $path;
    }

    private function toAdminDetail(Animal $animal): array
    {
        $animal->load(['photos', 'medicalRecords', 'vaccinations']);
        $qrCode = $this->ensureQrCode($animal);

        return [
            'id' => $animal->id,
            'name' => $animal->name,
            'species' => $animal->species,
            'breed' => $animal->breed,
            'age' => $animal->age,
            'gender' => $animal->gender,
            'size' => $animal->size,
            'weight' => $animal->weight,
            'status' => $animal->status,
            'rescue_story' => $animal->rescue_story,
            'behavioral_assessment' => $animal->behavioral_assessment,
            'qr_code' => $qrCode ? Storage::url($qrCode) : null,
            'photos' => $animal->photos->map(fn ($p) => [
                'id' => $p->id,
                'photo_url' => $p->photo_url ? Storage::url($p->photo_url) : null,
                'is_main' => (bool) $p->is_main,
            ])->values(),
            'medical_records' => $animal->medicalRecords->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'description' => $m->description,
                'vet_name' => $m->vet_name,
                'cost' => $m->cost,
                'record_date' => $m->record_date,
                'notes' => $m->notes,
            ])->values(),
            'vaccinations' => $animal->vaccinations->map(fn ($v) => [
                'id' => $v->id,
                'vaccine_name' => $v->vaccine_name,
                'date_given' => $v->date_given,
                'next_due' => $v->next_due,
            ])->values(),
        ];
    }

    private function careGuides(Animal $animal): array
    {
        // Species is entered freeform by admins ("Dog", "dog", "CAT", …) while guides
        // are seeded lowercase. Compare case-insensitively so the match works on
        // Postgres too (its string comparison is case-sensitive, unlike MySQL's
        // default collation).
        $guides = CareGuide::query()
            ->whereRaw('LOWER(species) = ?', [strtolower($animal->species ?? '')])
            ->get();

        $matched = [];

        // Determine age range
        $ageRange = null;
        if ($animal->age !== null) {
            if ($animal->age < 2) {
                $ageRange = 'puppy';
            } elseif ($animal->age < 7) {
                $ageRange = 'adult';
            } else {
                $ageRange = 'senior';
            }
        }

        foreach ($guides as $guide) {
            $matchScore = 0;

            // Age range is a hard filter, not just a bonus: an age-specific guide
            // (e.g. "Puppy Diet") should never surface for an animal of a different
            // life stage, even when its breed matches. Guides without an age_range
            // (behavioral, grooming, general) are unaffected. When the animal's age
            // is unknown we can't filter, so we let age-specific guides through.
            if ($guide->age_range && $ageRange && $guide->age_range !== $ageRange) {
                continue;
            }

            // Match by breed (exact or keyword match)
            if ($guide->breed_keywords && is_array($guide->breed_keywords)) {
                $breedLower = strtolower($animal->breed ?? '');
                foreach ($guide->breed_keywords as $keyword) {
                    if ($breedLower && strpos($breedLower, strtolower($keyword)) !== false) {
                        $matchScore += 2;
                    }
                }
            }

            // Match by age range
            if ($guide->age_range && $guide->age_range === $ageRange) {
                $matchScore += 1;
            }

            // Match by behavioral keywords
            $behaviors = $animal->behavioral_assessment;
            if ($guide->behavioral_keywords && is_array($guide->behavioral_keywords)) {
                if ($behaviors && is_array($behaviors)) {
                    foreach ($guide->behavioral_keywords as $keyword) {
                        if (in_array(strtolower($keyword), array_map('strtolower', $behaviors))) {
                            $matchScore += 3;
                        }
                    }
                }
            }

            // General guides (no keywords match everything)
            if (! $guide->breed_keywords && ! $guide->age_range && ! $guide->behavioral_keywords) {
                $matchScore += 0.5;
            }

            if ($matchScore > 0) {
                $matched[] = [
                    'id' => $guide->id,
                    'title' => $guide->title,
                    'category' => $guide->category,
                    'content' => $guide->content,
                    'matchScore' => $matchScore,
                    'is_behavioral' => ! empty($guide->behavioral_keywords),
                ];
            }
        }

        // Sort by: behavioral guides first (prioritized), then by match score descending
        usort($matched, function ($a, $b) {
            if ($a['is_behavioral'] !== $b['is_behavioral']) {
                return $a['is_behavioral'] ? -1 : 1;
            }

            return $b['matchScore'] <=> $a['matchScore'];
        });

        // Strip the internal scoring field but keep is_behavioral so the frontend
        // can visually prioritize behavior-matched guides.
        return array_map(function ($g) {
            unset($g['matchScore']);

            return $g;
        }, $matched);
    }

    private function toListItem(Animal $animal): array
    {
        return [
            'id' => $animal->id,
            'name' => $animal->name,
            'species' => $animal->species,
            'breed' => $animal->breed,
            'age' => $animal->age,
            'gender' => $animal->gender,
            'size' => $animal->size,
            'status' => $animal->status,
            'photo' => $animal->mainPhoto && $animal->mainPhoto->photo_url
                ? Storage::url($animal->mainPhoto->photo_url)
                : null,
        ];
    }
}
