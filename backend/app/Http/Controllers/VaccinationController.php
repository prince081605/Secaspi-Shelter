<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Reminder;
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
        $this->syncReminder($vaccination);

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
     * Keep a booster reminder in lockstep with the vaccination's next_due date:
     * create it when a due date is set, update it when the date changes, and remove
     * it when the due date is cleared. A reminder that was already actioned
     * (status completed) is left untouched.
     */
    private function syncReminder(Vaccination $vaccination): void
    {
        if (empty($vaccination->next_due)) {
            $this->deleteReminder($vaccination);
            return;
        }

        $animal = $vaccination->animal;
        $title = trim(($vaccination->vaccine_name ?: 'Vaccination') . ' booster due'
            . ($animal && $animal->name ? " — {$animal->name}" : ''));

        $existing = Reminder::where('remindable_type', Vaccination::class)
            ->where('remindable_id', $vaccination->id)
            ->first();

        // Preserve a completed reminder when the due date is unchanged (e.g. the admin
        // only fixed a typo in the vaccine name). Re-arm to pending only when the date
        // actually moves — that's a genuinely new booster to chase.
        $dueChanged = !$existing
            || optional($existing->reminder_date)->toDateString() !== \Illuminate\Support\Carbon::parse($vaccination->next_due)->toDateString();
        $status = ($existing && $existing->status === 'completed' && !$dueChanged)
            ? 'completed'
            : 'pending';

        Reminder::updateOrCreate(
            [
                'remindable_type' => Vaccination::class,
                'remindable_id' => $vaccination->id,
            ],
            [
                'animal_id' => $vaccination->animal_id,
                'title' => $title,
                'reminder_date' => $vaccination->next_due,
                'status' => $status,
            ]
        );
    }

    private function deleteReminder(Vaccination $vaccination): void
    {
        Reminder::where('remindable_type', Vaccination::class)
            ->where('remindable_id', $vaccination->id)
            ->delete();
    }
}
