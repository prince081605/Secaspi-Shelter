<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\FosterApplication;
use App\Notifications\FosterStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class FosterApplicationController extends Controller
{
    public function store(Request $request, Animal $animal)
    {
        if ($request->input('end_date') === '') {
            $request->merge(['end_date' => null]);
        }

        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'housing_type' => ['nullable', 'string', 'max:100'],
            'pet_experience' => ['nullable', 'string'],
            'reason' => ['required', 'string'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // One foster request per animal per user within a 30-day window — regardless of the
        // earlier request's outcome. After 30 days they may apply for this animal again, or
        // foster a different animal anytime. Mirrors the adoption-application guard.
        $alreadyApplied = FosterApplication::where('user_id', $user->id)
            ->where('animal_id', $animal->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'message' => "You've already applied to foster {$animal->name}. You can apply for this animal again 30 days after your last request.",
            ], 409);
        }

        try {
            $application = FosterApplication::create([
                'user_id' => $user->id,
                'animal_id' => $animal->id,
                'status' => 'pending',
                ...$validator->validated(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create foster application', [
                'user_id' => $user->id,
                'animal_id' => $animal->id,
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to submit foster application. Please try again.'], 500);
        }

        return response()->json([
            'application' => [
                'id' => $application->id,
                'status' => $application->status,
                'animal_id' => $application->animal_id,
                'start_date' => $application->start_date,
                'end_date' => $application->end_date,
            ],
        ], 201);
    }

    public function index(Request $request)
    {
        $applications = FosterApplication::where('user_id', $request->user()->id)
            ->with('animal.mainPhoto')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn (FosterApplication $a) => [
                'id' => $a->id,
                'status' => $a->status,
                'start_date' => $a->start_date,
                'end_date' => $a->end_date,
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
        $query = FosterApplication::query()->with(['animal.mainPhoto', 'user']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $applications = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $applications->getCollection()->transform(fn (FosterApplication $a) => $this->toAdminItem($a));

        return response()->json($applications);
    }

    public function adminUpdate(Request $request, FosterApplication $application)
    {
        if ($request->input('end_date') === '') {
            $request->merge(['end_date' => null]);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', 'in:pending,approved,active,completed,declined'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $previousStatus = $application->status;
        $application->update($validator->validated());
        $statusChanged = $application->wasChanged('status');

        // Keep the animal's public status in sync with the foster lifecycle (mirrors adoption).
        // Fostering starts → reserve the animal (out of the adoption pool); it ends → release it.
        if ($statusChanged) {
            if ($application->status === 'active') {
                $application->animal()->update(['status' => 'fostered']);
            } elseif (in_array($application->status, ['completed', 'declined'], true) && $previousStatus === 'active') {
                $application->animal()->update(['status' => 'available']);
            }
        }

        $application = $application->fresh(['animal.mainPhoto', 'user']);

        if ($statusChanged) {
            (new FosterStatusChanged($application))->sendTo($application->user);
        }

        return response()->json(['application' => $this->toAdminItem($application)]);
    }

    private function toAdminItem(FosterApplication $a): array
    {
        return [
            'id' => $a->id,
            'status' => $a->status,
            'full_name' => $a->full_name,
            'address' => $a->address,
            'occupation' => $a->occupation,
            'housing_type' => $a->housing_type,
            'pet_experience' => $a->pet_experience,
            'reason' => $a->reason,
            'start_date' => $a->start_date,
            'end_date' => $a->end_date,
            'notes' => $a->notes,
            'created_at' => $a->created_at,
            'animal' => $a->animal ? [
                'id' => $a->animal->id,
                'name' => $a->animal->name,
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
