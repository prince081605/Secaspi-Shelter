<?php

namespace App\Services;

use App\Models\AdoptionApplication;
use App\Models\AdoptionFollowUp;
use App\Models\AdoptionReturn;
use App\Models\AdoptionStatusChange;
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
 *  - Staff keep a post-adoption status on each adoption (STATUSES) — in home, lost contact,
 *    missing, deceased, rehomed, returned — and every change goes on the adoption's timeline.
 */
class PostAdoption
{
    /** Check-in => days after the adoption was completed. */
    public const SCHEDULE = ['1 week' => 7, '1 month' => 30, '3 months' => 90, '6 months' => 180];

    /** Where an adopted animal is now. In home is where every completed adoption starts. */
    public const STATUSES = [
        'in_home' => 'In home',
        'lost_contact' => 'Lost contact',
        'missing' => 'Missing',
        'deceased' => 'Deceased',
        'rehomed' => 'Rehomed',
        'returned' => 'Returned',
    ];

    /**
     * No check-ins while the animal is in one of these: it isn't with the family (missing,
     * rehomed, returned) or has died. Missing only pauses them — the ones still ahead come back if
     * the animal is found. Lost contact keeps them, so staff keep trying to reach the family.
     */
    private const NO_CHECK_INS = ['missing', 'deceased', 'rehomed', 'returned'];

    /** The adopter can send updates (a missing pet's family can tell us it's been found)… */
    public const ADOPTER_CAN_UPDATE = ['in_home', 'lost_contact', 'missing'];

    /** …and ask to return the animal only while it's with them. */
    public const ADOPTER_CAN_RETURN = ['in_home', 'lost_contact'];

    /** Happy Tails show "where they are now", so only stories from animals still in their homes. */
    public const ON_HAPPY_TAILS = ['in_home', 'lost_contact'];

    /**
     * What the family can report themselves from My Adopted Pets (status => the statuses it can
     * be reported from). It takes effect at once and the desk is alerted; staff can correct it.
     * Returned stays a return request that staff decide, and Lost contact is the staff's call.
     */
    public const ADOPTER_CAN_REPORT = [
        'missing' => ['in_home', 'lost_contact'],
        'deceased' => ['in_home', 'lost_contact', 'missing'],
        'rehomed' => ['in_home', 'lost_contact'],
        'in_home' => ['missing'], // found again
    ];

    /** The reports the family can make about an adoption that's currently in $current. */
    public static function adopterReports(?string $current): array
    {
        return array_keys(array_filter(self::ADOPTER_CAN_REPORT, fn (array $from) => in_array($current, $from, true)));
    }

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
            if (! $application->post_adoption_status) {
                $application->forceFill(['post_adoption_status' => 'in_home'])->save();
                $this->log($application, null, 'in_home', ['notes' => 'Adoption completed.'], null);
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

    /**
     * Staff change where the adopted animal is now. The side effects follow the status:
     *  - deceased: the animal's record is marked Deceased (and goes back to Adopted if that was a mistake);
     *  - missing, deceased, rehomed: the check-ins still ahead are called off; back to In home or
     *    Lost contact, the ones not yet due come back;
     *  - returned: ends the adoption, as an accepted return request does — the animal goes back into
     *    shelter care (`animal_status`). A pending return request from the adopter is accepted with it.
     * The caller has checked the change is allowed (see PostAdoptionController::updateStatus).
     *
     * @param  array{happened_on?: ?string, notes?: ?string, animal_status?: ?string}  $data
     */
    public function changeStatus(AdoptionApplication $application, string $to, array $data, ?User $by): AdoptionStatusChange
    {
        if ($to === 'returned' && ($request = $application->returns()->where('status', 'pending')->first())) {
            $this->resolveReturn($request, 'accepted', $data['animal_status'] ?? null, $data['notes'] ?? null, $by, $data['happened_on'] ?? null);

            return $application->statusChanges()->first();
        }

        return DB::transaction(function () use ($application, $to, $data, $by) {
            $from = $application->post_adoption_status;

            if ($to === 'returned') {
                $application->update(['status' => 'returned']);
                $application->animal()->update(['status' => $data['animal_status']]);
            } elseif ($to === 'deceased') {
                $application->animal()->update(['status' => 'deceased']);
            } elseif ($from === 'deceased') {
                $application->animal()->update(['status' => 'adopted']);
            }

            if (in_array($to, self::NO_CHECK_INS, true)) {
                $this->stop($application);
            } elseif (in_array($from, self::NO_CHECK_INS, true)) {
                $this->resume($application);
            }

            $application->forceFill(['post_adoption_status' => $to])->save();

            return $this->log($application, $from, $to, $data, $by);
        });
    }

    /** Bring back the check-ins that were called off and aren't yet past their due date. */
    private function resume(AdoptionApplication $application): void
    {
        $application->followUps()->where('status', 'cancelled')->whereDate('due_date', '>=', now()->toDateString())->get()
            ->each(function (AdoptionFollowUp $f) {
                $f->update(['status' => 'pending']);
                $this->mirrorReminder($f);
            });
    }

    private function log(AdoptionApplication $application, ?string $from, string $to, array $data, ?User $by): AdoptionStatusChange
    {
        return AdoptionStatusChange::create([
            'adoption_application_id' => $application->id,
            'animal_id' => $application->animal_id,
            'from_status' => $from,
            'to_status' => $to,
            'happened_on' => $data['happened_on'] ?? now()->toDateString(),
            'animal_status' => $to === 'returned' ? ($data['animal_status'] ?? null) : null,
            'notes' => $data['notes'] ?? null,
            'changed_by' => $by?->id,
        ]);
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

        // A family the shelter had lost touch with has just been in touch.
        $application = $update->application;
        if ($application && $application->post_adoption_status === 'lost_contact') {
            $this->changeStatus($application, 'in_home', ['notes' => 'The adopter got back in touch with an update.'], null);
        }
    }

    /**
     * Accept or decline a return request. Accepting ends the adoption: the application becomes
     * Returned, the animal goes back into shelter care with the chosen status, and the check-ins
     * still ahead are cancelled — all or nothing.
     */
    public function resolveReturn(AdoptionReturn $return, string $decision, ?string $animalStatus, ?string $notes, ?User $by, ?string $happenedOn = null): AdoptionReturn
    {
        DB::transaction(function () use ($return, $decision, $animalStatus, $notes, $by, $happenedOn) {
            $return->forceFill([
                'status' => $decision,
                'animal_status' => $decision === 'accepted' ? $animalStatus : null,
                'staff_notes' => $notes,
                'resolved_by' => $by?->id,
                'resolved_at' => now(),
            ])->save();

            if ($decision === 'accepted') {
                $application = $return->application;
                $from = $application->post_adoption_status;
                $application->update(['status' => 'returned']);
                $application->forceFill(['post_adoption_status' => 'returned'])->save();
                $application->animal()->update(['status' => $animalStatus]);
                $this->stop($application);
                $this->log($application, $from, 'returned', [
                    'happened_on' => $happenedOn,
                    'animal_status' => $animalStatus,
                    'notes' => $notes ?: 'Return request accepted.',
                ], $by);
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

    public function alertStaff(AdoptionUpdate|AdoptionReturn|AdoptionStatusChange $item): void
    {
        $item->loadMissing(['animal', $item instanceof AdoptionStatusChange ? 'changer' : 'user']);
        User::withPermission('desk.operate')->get()
            ->each(fn (User $member) => (new PostAdoptionAlert($item))->sendTo($member));
    }

    /** Show the check-in in Health Reminders too (and so in its daily email to Desk Admins). */
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
