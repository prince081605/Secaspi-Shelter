<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Intake;
use App\Models\IntakeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class IntakeController extends Controller
{
    private const TYPES = ['rescue', 'owner_surrender', 'stray'];
    private const STATUSES = ['pending', 'under_assessment', 'approved', 'converted', 'rejected'];

    public function adminIndex(Request $request)
    {
        $query = Intake::query();

        if ($type = $request->query('intake_type')) {
            $query->where('intake_type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            // Once an intake is added to Animals it leaves the active queue; the record is kept
            // (with its converted_animal_id link) and can still be found via the "converted" filter.
            $query->where('status', '!=', 'converted');
        }

        $intakes = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $intakes->getCollection()->transform(fn (Intake $i) => $this->toListItem($i));

        return response()->json($intakes);
    }

    public function adminShow(Intake $intake)
    {
        return response()->json(['intake' => $this->toDetail($intake)]);
    }

    public function adminStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'intake_type' => ['required', 'in:' . implode(',', self::TYPES)],
            'reporter_name' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string'],
            'animal_name' => ['nullable', 'string', 'max:100'],
            'species' => ['nullable', 'string', 'max:50'],
            'breed' => ['nullable', 'string', 'max:100'],
            'estimated_age' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', 'in:male,female'],
            'description' => ['nullable', 'string'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['image', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $documents = $data['documents'] ?? null;
        unset($data['documents']);

        $intake = Intake::create($data);

        if ($documents) {
            foreach ($documents as $file) {
                $intake->documents()->create([
                    'file_path' => $file->store('intakes', 'local'),
                    'original_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        return response()->json(['intake' => $this->toDetail($intake->fresh())], 201);
    }

    public function adminUpdate(Request $request, Intake $intake)
    {
        $validator = Validator::make($request->all(), [
            'intake_type' => ['sometimes', 'in:' . implode(',', self::TYPES)],
            'reporter_name' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string'],
            'animal_name' => ['nullable', 'string', 'max:100'],
            'species' => ['nullable', 'string', 'max:50'],
            'breed' => ['nullable', 'string', 'max:100'],
            'estimated_age' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', 'in:male,female'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:' . implode(',', self::STATUSES)],
            'assessment_notes' => ['nullable', 'string'],
            'assessed_by' => ['nullable', 'string', 'max:150'],
            'assessment_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        if ($request->input('status') === 'converted') {
            return response()->json(['message' => 'Use the convert action to mark an intake as converted.'], 422);
        }

        $intake->update($validator->validated());

        return response()->json(['intake' => $this->toDetail($intake)]);
    }

    public function adminConvert(Intake $intake)
    {
        if ($intake->status === 'converted') {
            return response()->json(['message' => 'This intake has already been converted.'], 409);
        }

        $animal = Animal::create([
            'name' => $intake->animal_name ?: 'Unnamed',
            'species' => $intake->species,
            'breed' => $intake->breed,
            'gender' => $intake->gender,
            'status' => 'quarantine',
            'rescue_story' => $intake->description,
        ]);

        // Carry the intake's photos over to the new animal so staff don't have to re-upload.
        // Copy (not reference) the files into the animals/ folder so deleting the intake — which
        // cascades + Storage::delete()s its documents — never removes the animal's photos. The
        // first photo becomes the main one, matching AnimalController's upload convention.
        // Documents live on the private 'local' disk; animal photos are public (shown on the
        // public adoption pages), so this is a cross-disk copy rather than a same-disk Storage::copy().
        $intake->load('documents');
        foreach ($intake->documents as $i => $doc) {
            if (!$doc->file_path || !Storage::disk('local')->exists($doc->file_path)) {
                continue;
            }
            $ext = pathinfo($doc->file_path, PATHINFO_EXTENSION);
            $newPath = 'animals/' . Str::random(40) . ($ext ? '.' . $ext : '');
            $stream = Storage::disk('local')->readStream($doc->file_path);
            Storage::disk('public')->writeStream($newPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $animal->photos()->create([
                'photo_url' => $newPath,
                'is_main' => $i === 0,
            ]);
        }

        $intake->update(['status' => 'converted', 'converted_animal_id' => $animal->id]);

        return response()->json([
            'intake' => $this->toDetail($intake),
            'animal' => ['id' => $animal->id, 'name' => $animal->name],
        ]);
    }

    public function adminDestroy(Intake $intake)
    {
        foreach ($intake->documents as $doc) {
            Storage::disk('local')->delete($doc->file_path);
        }
        $intake->delete();

        return response()->json(['message' => 'Intake deleted']);
    }

    public function addDocuments(Request $request, Intake $intake)
    {
        $validator = Validator::make($request->all(), [
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['image', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $created = [];
        foreach ($request->file('documents') as $file) {
            $created[] = $intake->documents()->create([
                'file_path' => $file->store('intakes', 'local'),
                'original_name' => $file->getClientOriginalName(),
            ]);
        }

        return response()->json(['documents' => $created], 201);
    }

    public function destroyDocument(Intake $intake, IntakeDocument $document)
    {
        if ($document->intake_id !== $intake->id) {
            return response()->json(['message' => 'Document not found for this intake'], 404);
        }

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    private function toListItem(Intake $i): array
    {
        return [
            'id' => $i->id,
            'intake_type' => $i->intake_type,
            'reporter_name' => $i->reporter_name,
            'animal_name' => $i->animal_name,
            'species' => $i->species,
            'status' => $i->status,
            'converted_animal_id' => $i->converted_animal_id,
            'created_at' => $i->created_at,
        ];
    }

    private function toDetail(Intake $i): array
    {
        $i->load('documents');

        return [
            'id' => $i->id,
            'intake_type' => $i->intake_type,
            'reporter_name' => $i->reporter_name,
            'contact_number' => $i->contact_number,
            'location' => $i->location,
            'animal_name' => $i->animal_name,
            'species' => $i->species,
            'breed' => $i->breed,
            'estimated_age' => $i->estimated_age,
            'gender' => $i->gender,
            'description' => $i->description,
            'status' => $i->status,
            'assessment_notes' => $i->assessment_notes,
            'assessed_by' => $i->assessed_by,
            'assessment_date' => $i->assessment_date,
            'converted_animal_id' => $i->converted_animal_id,
            'created_at' => $i->created_at,
            'documents' => $i->documents->map(fn ($d) => [
                'id' => $d->id,
                'file_path' => $d->file_path ? Storage::disk('local')->temporaryUrl($d->file_path, now()->addMinutes(30)) : null,
                'original_name' => $d->original_name,
            ])->values(),
        ];
    }
}
