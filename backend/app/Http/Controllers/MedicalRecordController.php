<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\CategoryOption;
use App\Models\MedicalRecord;
use App\Rules\NotInFuture;
use App\Support\SyncsHealthReminders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MedicalRecordController extends Controller
{
    use SyncsHealthReminders;

    // Record types are an admin-managed list (CategoryOption 'medical_record_type').

    public function store(Request $request, Animal $animal)
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', Rule::in(CategoryOption::activeValues('medical_record_type'))],
            'description' => ['nullable', 'string'],
            'vet_name' => ['nullable', 'string', 'max:150'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'record_date' => ['required', 'date', new NotInFuture],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:record_date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $record = $animal->medicalRecords()->create($validator->validated());
        $this->syncRecordReminder($record);

        return response()->json(['record' => $record], 201);
    }

    public function update(Request $request, MedicalRecord $record)
    {
        $validator = Validator::make($request->all(), [
            // A record whose type has since been hidden keeps it when its other fields are edited.
            'type' => ['sometimes', Rule::in([...CategoryOption::activeValues('medical_record_type'), $record->type])],
            'description' => ['nullable', 'string'],
            'vet_name' => ['nullable', 'string', 'max:150'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'record_date' => ['sometimes', 'date', new NotInFuture],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:record_date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
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
        $title = trim((CategoryOption::labelFor('medical_record_type', $record->type) ?: 'Medical') . ' follow-up'
            . ($animal && $animal->name ? " — {$animal->name}" : ''));

        $this->syncHealthReminder(
            $record,
            $record->animal_id,
            $title,
            optional($record->follow_up_date)->toDateString()
        );
    }
}
