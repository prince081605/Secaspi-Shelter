<?php

namespace App\Support;

use App\Models\Reminder;
use Illuminate\Support\Carbon;

/**
 * Shared logic for keeping a health Reminder in lockstep with a source record's due date
 * (a vaccination's next_due, a medical record's follow_up_date). Create when a due date is
 * set, update when it moves, and remove when it's cleared. A reminder already actioned
 * (status completed) is preserved when the due date is unchanged.
 *
 * Used by VaccinationController and MedicalRecordController so the behaviour stays identical.
 */
trait SyncsHealthReminders
{
    protected function syncHealthReminder(object $record, int $animalId, string $title, ?string $dueDate): void
    {
        if (empty($dueDate)) {
            $this->deleteHealthReminder($record);
            return;
        }

        $existing = Reminder::where('remindable_type', $record::class)
            ->where('remindable_id', $record->id)
            ->first();

        // Re-arm to pending only when the date actually moves — a genuinely new follow-up to
        // chase. If the admin only fixed a typo (date unchanged), keep a completed reminder done.
        $dueChanged = ! $existing
            || optional($existing->reminder_date)->toDateString() !== Carbon::parse($dueDate)->toDateString();
        $status = ($existing && $existing->status === 'completed' && ! $dueChanged) ? 'completed' : 'pending';

        Reminder::updateOrCreate(
            [
                'remindable_type' => $record::class,
                'remindable_id' => $record->id,
            ],
            [
                'animal_id' => $animalId,
                'title' => $title,
                'reminder_date' => $dueDate,
                'status' => $status,
            ]
        );
    }

    protected function deleteHealthReminder(object $record): void
    {
        Reminder::where('remindable_type', $record::class)
            ->where('remindable_id', $record->id)
            ->delete();
    }
}
