<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Reminder;
use App\Models\Vaccination;
use App\Support\SyncsHealthReminders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VaccinationController extends Controller
{
    use SyncsHealthReminders;

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
        $this->syncReminder($vaccination);
        $this->clearSupersededBoosters($vaccination);

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
        $this->syncReminder($vaccination);

        return response()->json(['vaccination' => $vaccination]);
    }

    public function destroy(Vaccination $vaccination)
    {
        $this->deleteReminder($vaccination);
        $vaccination->delete();

        return response()->json(['message' => 'Vaccination record deleted']);
    }

    /**
     * Keep a booster reminder in lockstep with the vaccination's next_due date.
     * Delegates the create/update/delete logic to the shared SyncsHealthReminders trait.
     */
    private function syncReminder(Vaccination $vaccination): void
    {
        $animal = $vaccination->animal;
        $title = trim(($vaccination->vaccine_name ?: 'Vaccination') . ' booster due'
            . ($animal && $animal->name ? " — {$animal->name}" : ''));

        $this->syncHealthReminder($vaccination, $vaccination->animal_id, $title, $vaccination->next_due);
    }

    private function deleteReminder(Vaccination $vaccination): void
    {
        $this->deleteHealthReminder($vaccination);
    }

    /**
     * Recording a fresh shot of the same vaccine for an animal means any earlier booster we
     * were chasing has now been given — so mark those prior (still-open) reminders completed.
     * This keeps the reminders list clean without ever hiding a genuinely missed booster:
     * an overdue reminder only clears once a newer shot proves the care actually happened.
     */
    private function clearSupersededBoosters(Vaccination $new): void
    {
        $olderIds = Vaccination::where('animal_id', $new->animal_id)
            ->whereRaw('LOWER(vaccine_name) = ?', [mb_strtolower($new->vaccine_name)])
            ->where('id', '!=', $new->id)
            ->pluck('id');

        if ($olderIds->isEmpty()) {
            return;
        }

        Reminder::where('remindable_type', Vaccination::class)
            ->whereIn('remindable_id', $olderIds)
            ->whereIn('status', ['pending', 'sent'])
            ->update(['status' => 'completed']);
    }
}
