<?php

namespace App\Http\Controllers;

use App\Models\ShelterLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The shelter's named areas (House 1, Kennel 2, ...). Staff read the list to move animals
 * around; only admins add, rename or remove areas.
 */
class ShelterLocationController extends Controller
{
    public function index()
    {
        $locations = ShelterLocation::withCount('animals')->orderBy('name')->get();

        return response()->json([
            'locations' => $locations->map(fn (ShelterLocation $l) => $this->toItem($l))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100', Rule::unique('shelter_locations', 'name')],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $location = ShelterLocation::create(['name' => trim($validator->validated()['name'])]);

        return response()->json(['location' => $this->toItem($location->loadCount('animals'))], 201);
    }

    public function update(Request $request, ShelterLocation $location)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100', Rule::unique('shelter_locations', 'name')->ignore($location->id)],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $location->update(['name' => trim($validator->validated()['name'])]);

        return response()->json(['location' => $this->toItem($location->loadCount('animals'))]);
    }

    /** Only an empty area can be removed, so no animal silently loses its location. */
    public function destroy(ShelterLocation $location)
    {
        $count = $location->animals()->count();
        if ($count > 0) {
            return response()->json([
                'message' => "{$location->name} still has {$count} animal(s) in it. Move them to another area first.",
            ], 409);
        }

        $location->delete();

        return response()->json(['message' => 'Location removed']);
    }

    private function toItem(ShelterLocation $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'animal_count' => (int) ($l->animals_count ?? 0),
        ];
    }
}
