<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalPhoto;
use App\Services\CloudinaryUploader;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AnimalController extends Controller
{
    private const STATUSES = ['available', 'adopted', 'fostered', 'medical', 'quarantine', 'archived'];

    public function index(Request $request)
    {
        $query = Animal::query()->where('status', '!=', 'archived');

        if ($q = $request->query('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('species', 'like', "%{$q}%")
                    ->orWhere('breed', 'like', "%{$q}%");
            });
        }

        foreach (['species', 'gender', 'size', 'status'] as $field) {
            if ($value = $request->query($field)) {
                $query->where($field, $value);
            }
        }

        $animals = $query->with('mainPhoto')
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
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('species', 'like', "%{$q}%")
                    ->orWhere('breed', 'like', "%{$q}%");
            });
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
                'qr_code' => $qrCode,
                'photos' => $animal->photos->pluck('photo_url')->values(),
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
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'species' => ['required', 'string', 'max:50'],
            'breed' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'min:0'],
            'gender' => ['nullable', 'in:male,female'],
            'size' => ['nullable', 'in:small,medium,large'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:' . implode(',', self::STATUSES)],
            'rescue_story' => ['nullable', 'string'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $photos = $data['photos'] ?? null;
        unset($data['photos']);
        $data['status'] = $data['status'] ?? 'available';

        $animal = Animal::create($data);

        if ($photos) {
            foreach ($photos as $i => $file) {
                $animal->photos()->create([
                    'photo_url' => CloudinaryUploader::uploadFile($file, 'animals'),
                    'is_main' => $i === 0,
                ]);
            }
        }

        return response()->json(['animal' => $this->toAdminDetail($animal)], 201);
    }

    public function update(Request $request, Animal $animal)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:100'],
            'species' => ['sometimes', 'string', 'max:50'],
            'breed' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'min:0'],
            'gender' => ['nullable', 'in:male,female'],
            'size' => ['nullable', 'in:small,medium,large'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:' . implode(',', self::STATUSES)],
            'rescue_story' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $animal->update($validator->validated());

        return response()->json(['animal' => $this->toAdminDetail($animal)]);
    }

    public function archive(Animal $animal)
    {
        $animal->update(['status' => 'archived']);

        return response()->json(['animal' => $this->toAdminDetail($animal)]);
    }

    public function destroy(Animal $animal)
    {
        // The DB's FKs on medical_records/vaccinations/adoption_applications/foster_applications
        // are all ON DELETE CASCADE, so a raw delete() would silently wipe this animal's entire
        // history with no warning. Block it explicitly instead of relying on a constraint violation.
        $hasDependents = $animal->medicalRecords()->exists()
            || $animal->vaccinations()->exists()
            || $animal->adoptionApplications()->exists()
            || $animal->fosterApplications()->exists();

        if ($hasDependents) {
            return response()->json([
                'message' => 'This animal has related records (medical history, applications, etc.) and cannot be deleted. Archive it instead.',
            ], 409);
        }

        foreach ($animal->photos as $photo) {
            if ($photo->photo_url) {
                CloudinaryUploader::delete($photo->photo_url);
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
                'photo_url' => CloudinaryUploader::uploadFile($file, 'animals'),
                'is_main' => !$hasMain && $i === 0,
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
            CloudinaryUploader::delete($photo->photo_url);
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
    private function ensureQrCode(Animal $animal): ?string
    {
        if ($animal->qr_code_path) {
            return $animal->qr_code_path;
        }

        $url = rtrim(config('app.frontend_url'), '/') . '/adopt/' . $animal->id;

        $result = (new Builder(
            writer: new SvgWriter(),
            data: $url,
            size: 300,
            margin: 10,
        ))->build();

        $path = CloudinaryUploader::uploadContent($result->getString(), 'qr-codes', 'image/svg+xml', 'svg');
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
            'qr_code' => $qrCode,
            'photos' => $animal->photos->map(fn ($p) => [
                'id' => $p->id,
                'photo_url' => $p->photo_url,
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
            'photo' => optional($animal->mainPhoto)->photo_url,
        ];
    }
}
