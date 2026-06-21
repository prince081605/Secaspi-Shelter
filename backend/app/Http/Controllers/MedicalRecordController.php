<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\MedicalRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MedicalRecordController extends Controller
{
    private const TYPES = ['vaccination', 'deworming', 'treatment', 'surgery', 'checkup', 'emergency'];

    public function store(Request $request, Animal $animal)
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'in:' . implode(',', self::TYPES)],
            'description' => ['nullable', 'string'],
            'vet_name' => ['nullable', 'string', 'max:150'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'record_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $record = $animal->medicalRecords()->create($validator->validated());

        return response()->json(['record' => $record], 201);
    }

    public function update(Request $request, MedicalRecord $record)
    {
        $validator = Validator::make($request->all(), [
            'type' => ['sometimes', 'in:' . implode(',', self::TYPES)],
            'description' => ['nullable', 'string'],
            'vet_name' => ['nullable', 'string', 'max:150'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'record_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $record->update($validator->validated());

        return response()->json(['record' => $record]);
    }

    public function destroy(MedicalRecord $record)
    {
        $record->delete();

        return response()->json(['message' => 'Medical record deleted']);
    }
}
