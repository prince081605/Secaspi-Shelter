<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Services\AdoptionMatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MatchmakerController extends Controller
{
    public function match(Request $request, AdoptionMatchService $matcher)
    {
        $validator = Validator::make($request->all(), [
            'home_type' => ['required', 'in:apartment,house_no_yard,house_with_yard'],
            'activity_level' => ['required', 'in:low,moderate,high'],
            'experience' => ['required', 'in:first_time,some,experienced'],
            'household' => ['required', 'in:kids,other_pets,quiet_adults_only'],
            'preferred_species' => ['nullable', 'in:any,dog,cat'],
            'preferred_size' => ['nullable', 'in:any,small,medium,large'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $answers = $validator->validated();

        $animals = Animal::with('mainPhoto')
            ->where('status', 'available')
            ->get();

        $results = $animals
            ->map(function (Animal $animal) use ($matcher, $answers) {
                $match = $matcher->score($animal, $answers);

                return [
                    'animal' => [
                        'id' => $animal->id,
                        'name' => $animal->name,
                        'species' => $animal->species,
                        'breed' => $animal->breed,
                        'age' => $animal->age,
                        'size' => $animal->size,
                        'photo' => optional($animal->mainPhoto)->photo_url
                            ? Storage::url($animal->mainPhoto->photo_url) : null,
                    ],
                    'percent' => $match['percent'],
                    'reasons' => $match['reasons'],
                    'cautions' => $match['cautions'],
                ];
            })
            ->sortByDesc('percent')
            ->values();

        return response()->json(['matches' => $results]);
    }
}
