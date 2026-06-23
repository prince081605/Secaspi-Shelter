<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AdoptionApplication;
use App\Notifications\AdoptionStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdoptionApplicationController extends Controller
{
    public function store(Request $request, Animal $animal)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'housing_type' => ['nullable', 'string', 'max:50'],
            'pet_experience' => ['nullable', 'string'],
            'reason' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $referenceNo = 'ADP-' . strtoupper(Str::random(8));

        try {
            $application = AdoptionApplication::create([
                'user_id' => $user->id,
                'animal_id' => $animal->id,
                'reference_no' => $referenceNo,
                'status' => 'pending',
                ...$validator->validated(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create adoption application', [
                'user_id' => $user->id,
                'animal_id' => $animal->id,
                'reference_no' => $referenceNo,
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to submit application. Please try again.'], 500);
        }

        return response()->json([
            'application' => [
                'id' => $application->id,
                'reference_no' => $application->reference_no,
                'status' => $application->status,
                'animal_id' => $application->animal_id,
            ],
        ], 201);
    }

    public function index(Request $request)
    {
        $applications = AdoptionApplication::where('user_id', $request->user()->id)
            ->with('animal.mainPhoto')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn (AdoptionApplication $a) => [
                'id' => $a->id,
                'reference_no' => $a->reference_no,
                'status' => $a->status,
                'created_at' => $a->created_at,
                'animal' => $a->animal ? [
                    'id' => $a->animal->id,
                    'name' => $a->animal->name,
                    'photo' => optional($a->animal->mainPhoto)->photo_url ? Storage::url($a->animal->mainPhoto->photo_url) : null,
                ] : null,
            ])->values(),
        ]);
    }

    public function adminIndex(Request $request)
    {
        $query = AdoptionApplication::query()->with(['animal.mainPhoto', 'user']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $applications = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $applications->getCollection()->transform(fn (AdoptionApplication $a) => $this->toAdminItem($a));

        return response()->json($applications);
    }

    public function adminUpdate(Request $request, AdoptionApplication $application)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', 'in:pending,approved,declined,completed'],
            'home_visit_status' => ['sometimes', 'in:not_scheduled,scheduled,completed'],
            'home_visit_date' => ['nullable', 'date'],
            'home_visit_notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (is_null($application->read_at)) {
            $data['read_at'] = now();
        }

        $previousStatus = $application->status;
        $application->update($data);
        $statusChanged = $application->wasChanged('status');

        if ($statusChanged && $application->status === 'approved') {
            // Once approved, the animal is reserved for this adopter and should disappear
            // from the public adoption listing until/unless the application is later declined.
            $application->animal()->update(['status' => 'adopted']);
        } elseif ($statusChanged && $application->status === 'declined' && $previousStatus === 'approved') {
            $application->animal()->update(['status' => 'available']);
        }

        $application = $application->fresh(['animal.mainPhoto', 'user']);

        if ($statusChanged) {
            (new AdoptionStatusChanged($application))->sendTo($application->user);
        }

        return response()->json(['application' => $this->toAdminItem($application)]);
    }

    /**
     * Marks an application read the first time an admin opens its details, independent of
     * any status-changing action — without this, an admin who only reviews (but never
     * approves/declines) an application would never clear its unread highlight/badge count.
     */
    public function adminMarkRead(AdoptionApplication $application)
    {
        if (is_null($application->read_at)) {
            $application->update(['read_at' => now()]);
        }

        return response()->json(['application' => $this->toAdminItem($application)]);
    }

    private function toAdminItem(AdoptionApplication $a): array
    {
        return [
            'id' => $a->id,
            'reference_no' => $a->reference_no,
            'status' => $a->status,
            'read_at' => $a->read_at,
            'home_visit_status' => $a->home_visit_status,
            'home_visit_date' => $a->home_visit_date,
            'home_visit_notes' => $a->home_visit_notes,
            'full_name' => $a->full_name,
            'address' => $a->address,
            'occupation' => $a->occupation,
            'housing_type' => $a->housing_type,
            'pet_experience' => $a->pet_experience,
            'reason' => $a->reason,
            'created_at' => $a->created_at,
            'animal' => $a->animal ? [
                'id' => $a->animal->id,
                'name' => $a->animal->name,
                'species' => $a->animal->species,
                'breed' => $a->animal->breed,
                'age' => $a->animal->age,
                'photo' => optional($a->animal->mainPhoto)->photo_url ? Storage::url($a->animal->mainPhoto->photo_url) : null,
            ] : null,
            'applicant' => $a->user ? [
                'id' => $a->user->id,
                'full_name' => $a->user->full_name,
                'email' => $a->user->email,
                'phone' => $a->user->phone,
            ] : null,
        ];
    }
}
