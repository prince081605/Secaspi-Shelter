<?php

namespace App\Notifications;

use App\Models\AdoptionFollowUp;

/** To the adopter, when a post-adoption check-in comes due: asks them for an update. */
class CheckInDue extends AppNotification
{
    public function __construct(private AdoptionFollowUp $followUp) {}

    public function type(): string
    {
        return 'adoption_check_in';
    }

    public function title(): string
    {
        return 'How is '.$this->animalName().' doing?';
    }

    public function message(): string
    {
        return "It has been {$this->followUp->label} since {$this->animalName()} went home with you. "
            .'We would love to hear how they are settling in — send us an update, with a photo if you can, '
            .'from My Adopted Pets on your dashboard.';
    }

    public function data(): array
    {
        return [
            'adoption_application_id' => $this->followUp->adoption_application_id,
            'follow_up_id' => $this->followUp->id,
        ];
    }

    private function animalName(): string
    {
        return $this->followUp->animal->name ?? 'your pet';
    }
}
