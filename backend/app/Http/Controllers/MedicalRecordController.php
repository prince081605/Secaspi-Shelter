<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\MedicalRecord;
use App\Support\SyncsHealthReminders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MedicalRecordController extends Controller
{
    use SyncsHealthReminders;

    private const TYPES = ['vaccination', 'deworming', 'treatment', 'surgery', 'checkup', 'emergency'];

    public function store(Request $request, Animal $animal)
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'in:' . implode(',', self::TYPES)],
            'description' => ['nullable', 'string'],
            'vet_name' => ['nullable', 'string', 'max:150'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'record_date' => ['required', 'date'],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:record_date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $record = $animal->medicalRecords()->create($validator->validated());
        $this->syncRecordReminder($record);

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
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:record_date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $record->update($validator->validated());
        $this->syncRecordReminder($record);

        return response()->json(['record' => $record]);
    }

    public function destroy(MedicalRecord $record)
    {
        $this->deleteHealthReminder($record);
        $record->delete();

        return response()->json(['message' => 'Medical record deleted']);
    }

    /**
     * Auto-create/update/remove a follow-up reminder from the record's follow_up_date,
     * mirroring how vaccinations track their booster due date.
     */
    private function syncRecordReminder(MedicalRecord $record): void
    {
        $animal = $record->animal;
        $title = trim(ucfirst($record->type ?: 'Medical') . ' follow-up'
            . ($animal && $animal->name ? " — {$animal->name}" : ''));

        $this->syncHealthReminder(
            $record,
            $record->animal_id,
            $title,
            optional($record->follow_up_date)->toDateString()
        );
    }
}
