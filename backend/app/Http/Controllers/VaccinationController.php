<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Vaccination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VaccinationController extends Controller
{
    public function store(Request $request, Animal $animal)
    {
        $validator = Validator::make($request->all(), [
            'vaccine_name' => ['required', 'string', 'max:150'],
            'date_given' => ['required', 'date'],
            'next_due' => ['nullable', 'date', 'after:date_given'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $vaccination = $animal->vaccinations()->create($validator->validated());

        return response()->json(['vaccination' => $vaccination], 201);
    }

    public function update(Request $request, Vaccination $vaccination)
    {
        $validator = Validator::make($request->all(), [
            'vaccine_name' => ['sometimes', 'string', 'max:150'],
            'date_given' => ['sometimes', 'date'],
            'next_due' => ['nullable', 'date', 'after:date_given'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $vaccination->update($validator->validated());

        return response()->json(['vaccination' => $vaccination]);
    }

    public function destroy(Vaccination $vaccination)
    {
        $vaccination->delete();

        return response()->json(['message' => 'Vaccination record deleted']);
    }
}
