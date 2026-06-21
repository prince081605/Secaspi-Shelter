<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\FosterApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FosterApplicationController extends Controller
{
    public function store(Request $request, Animal $animal)
    {
        if ($request->input('end_date') === '') {
            $request->merge(['end_date' => null]);
        }

        $validator = Validator::make($request->all(), [
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();

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
                    'photo' => optional($a->animal->mainPhoto)->photo_url,
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

        $application->update($validator->validated());

        return response()->json(['application' => $this->toAdminItem($application->fresh(['animal.mainPhoto', 'user']))]);
    }

    private function toAdminItem(FosterApplication $a): array
    {
        return [
            'id' => $a->id,
            'status' => $a->status,
            'start_date' => $a->start_date,
            'end_date' => $a->end_date,
            'notes' => $a->notes,
            'created_at' => $a->created_at,
            'animal' => $a->animal ? [
                'id' => $a->animal->id,
                'name' => $a->animal->name,
                'photo' => optional($a->animal->mainPhoto)->photo_url,
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
