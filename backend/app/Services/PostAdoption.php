<?php

namespace App\Services;

use App\Models\AdoptionApplication;
use App\Models\AdoptionFollowUp;
use App\Models\AdoptionReturn;
use App\Models\AdoptionUpdate;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\PostAdoptionAlert;
use App\Notifications\PostAdoptionNews;
use Illuminate\Support\Facades\DB;

/**
 * Post-adoption care, from the moment an adoption is marked Completed:
 *
 *  - Check-ins are scheduled at fixed points after the adoption (SCHEDULE). Each is mirrored as a
 *    Reminder, so it also shows (and gets the daily email) in Health Reminders.
 *  - An update from the adopter answers the check-in that's due, so staff don't chase an adopter
 *    who has just told them how things are going.
 *  - A return request, once accepted, ends the adoption: the application is marked Returned, the
 *    animal goes back into shelter care, and the check-ins still ahead are cancelled. Nothing is
 *    deleted — the adoption's history stays on record.
 */
class PostAdoption
{
    /** Check-in => days after the adoption was completed. */
    public const SCHEDULE = ['1 week' => 7, '1 month' => 30, '3 months' => 90, '6 months' => 180];

    /** Roughly how far ahead an adopter's update still counts as answering a check-in. */
    private const UPDATE_ANSWERS_WITHIN_DAYS = 7;

    /** The adopter's words for how the animal is doing, mapped to the scale staff record. */
    private const WELLBEING_FROM_ADOPTER = [
        'doing_well' => 'doing_well',
        'some_concerns' => 'needs_support',
        'need_help' => 'concern',
    ];

    /** Schedule the check-ins for a newly completed adoption. Safe to call again: it never doubles up. */
    public function start(AdoptionApplication $application): void
    {
        DB::transaction(function () use ($application) {
            if (! $application->completed_at) {
                $application->forceFill(['completed_at' => now()])->save();
            }

            if ($application->followUps()->exists()) {
                // Re-completed after being moved back: revive the check-ins that were called off.
                $application->followUps()->where('status', 'cancelled')->get()
                    ->each(function (AdoptionFollowUp $f) {
                        $f->update(['status' => 'pending']);
                        $this->mirrorReminder($f);
                    });

                return;
            }

            $from = $application->completed_at->copy()->startOfDay();
            foreach (self::SCHEDULE as $label => $days) {
                $followUp = $application->followUps()->create([
                    'animal_id' => $application->animal_id,
                    'label' => $label,
                    'due_date' => $from->copy()->addDays($days)->toDateString(),
                    'status' => 'pending',
                ]);
                $this->mirrorReminder($followUp);
            }
        });
    }

    /** Call off the check-ins still ahead (the adoption was moved back from Completed, or the animal returned). */
    public function stop(AdoptionApplication $application): void
    {
        $application->followUps()->where('status', 'pending')->get()
            ->each(function (AdoptionFollowUp $f) {
                $f->update(['status' => 'cancelled']);
                Reminder::where('remindable_type', AdoptionFollowUp::class)->where('remindable_id', $f->id)->delete();
            });
    }

    /** Staff record how a check-in went. */
    public function record(AdoptionFollowUp $followUp, array $data, ?User $by): AdoptionFollowUp
    {
        $followUp->forceFill([
            'status' => 'done',
            'contact_method' => $data['contact_method'],
            'wellbeing' => $data['wellbeing'],
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $by?->id,
            'completed_at' => now(),
        ])->save();

        Reminder::where('remindable_type', AdoptionFollowUp::class)->where('remindable_id', $followUp->id)
            ->update(['status' => 'completed']);

        return $followUp;
    }

    /**
     * An adopter's update arrived: let it answer the check-in that's due (or about to be), and
     * alert staff if the adopter says they need help.
     */
    public function received(AdoptionUpdate $update): void
    {
        $due = AdoptionFollowUp::where('adoption_application_id', $update->adoption_application_id)
            ->where('status', 'pending')
            ->whereDate('due_date', '<=', now()->addDays(self::UPDATE_ANSWERS_WITHIN_DAYS))
            ->orderBy('due_date')
            ->first();

        if ($due) {
            $this->record($due, [
                'contact_method' => 'adopter_update',
                'wellbeing' => self::WELLBEING_FROM_ADOPTER[$update->wellbeing],
                'notes' => 'The adopter sent an update.',
            ], null);
            $update->forceFill(['follow_up_id' => $due->id])->save();
        }

        if ($update->wellbeing === 'need_help') {
            $this->alertStaff($update);
        }
    }

    /**
     * Accept or decline a return request. Accepting ends the adoption: the application becomes
     * Returned, the animal goes back into shelter care with the chosen status, and the check-ins
     * still ahead are cancelled — all or nothing.
     */
    public function resolveReturn(AdoptionReturn $return, string $decision, ?string $animalStatus, ?string $notes, User $by): AdoptionReturn
    {
        DB::transaction(function () use ($return, $decision, $animalStatus, $notes, $by) {
            $return->forceFill([
                'status' => $decision,
                'animal_status' => $decision === 'accepted' ? $animalStatus : null,
                'staff_notes' => $notes,
                'resolved_by' => $by->id,
                'resolved_at' => now(),
            ])->save();

            if ($decision === 'accepted') {
                $application = $return->application;
                $application->update(['status' => 'returned']);
                $application->animal()->update(['status' => $animalStatus]);
                $this->stop($application);
            }
        });

        if ($return->user) {
            (new PostAdoptionNews($return->fresh(['animal'])))->sendTo($return->user);
        }

        return $return;
    }

    /** Staff approve or decline an update the adopter offered as a Happy Tails story. */
    public function reviewStory(AdoptionUpdate $update, string $decision, User $by): AdoptionUpdate
    {
        $update->forceFill([
            'story_status' => $decision,
            'story_reviewed_by' => $by->id,
            'story_reviewed_at' => now(),
            'read_at' => $update->read_at ?? now(),
        ])->save();

        if ($decision === 'approved' && $update->user) {
            (new PostAdoptionNews($update->fresh(['animal'])))->sendTo($update->user);
        }

        return $update;
    }

    public function alertStaff(AdoptionUpdate|AdoptionReturn $item): void
    {
        $item->loadMissing(['animal', 'user']);
        User::whereIn('role', ['staff', 'admin'])->get()
            ->each(fn (User $member) => (new PostAdoptionAlert($item))->sendTo($member));
    }

    /** Show the check-in in Health Reminders too (and so in its daily email to admins). */
    private function mirrorReminder(AdoptionFollowUp $followUp): void
    {
        $followUp->loadMissing(['animal', 'application']);
        $animal = $followUp->animal->name ?? 'adopted animal';
        $adopter = $followUp->application->full_name;

        Reminder::updateOrCreate(
            ['remindable_type' => AdoptionFollowUp::class, 'remindable_id' => $followUp->id],
            [
                'animal_id' => $followUp->animal_id,
                'title' => "Post-adoption check-in ({$followUp->label}) — {$animal}".($adopter ? ", adopted by {$adopter}" : ''),
                'reminder_date' => $followUp->due_date->toDateString(),
                'status' => 'pending',
            ],
        );
    }
}
