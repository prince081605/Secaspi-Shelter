<?php

namespace App\Notifications;

use App\Models\AdoptionReturn;
use App\Models\AdoptionStatusChange;
use App\Models\AdoptionUpdate;

/**
 * To staff and Desk Admins, when an adopter needs the shelter: an update saying they need help, a
 * request to return the animal, or a report that the animal is missing, has died, has a new
 * family or is home again. Everything else waits quietly on the Post-adoption page.
 */
class PostAdoptionAlert extends AppNotification
{
    private const REPORT_TITLES = [
        'missing' => 'Adopted animal reported missing',
        'deceased' => 'Adopted animal has passed away',
        'rehomed' => 'Adopted animal now lives with another family',
        'in_home' => 'Missing adopted animal is home',
    ];

    public function __construct(private AdoptionUpdate|AdoptionReturn|AdoptionStatusChange $item) {}

    public function type(): string
    {
        return 'post_adoption_alert';
    }

    public function title(): string
    {
        if ($this->item instanceof AdoptionStatusChange) {
            return self::REPORT_TITLES[$this->item->to_status] ?? 'An adopter reported a change';
        }

        return $this->item instanceof AdoptionReturn ? 'Adoption return requested' : 'An adopter needs help';
    }

    public function message(): string
    {
        $animal = $this->item->animal->name ?? 'an adopted animal';

        if ($this->item instanceof AdoptionStatusChange) {
            $adopter = $this->item->changer->full_name ?? 'The adopter';
            $what = match ($this->item->to_status) {
                'missing' => "{$animal} is missing",
                'deceased' => "{$animal} has passed away",
                'rehomed' => "{$animal} now lives with another family",
                'in_home' => "{$animal} is back home",
                default => "{$animal}'s status changed",
            };

            return "{$adopter} reported that {$what}. See Post-adoption → Adopted animals.";
        }

        $adopter = $this->item->user->full_name ?? 'An adopter';

        return $this->item instanceof AdoptionReturn
            ? "{$adopter} has asked to return {$animal}. Review it on the Post-adoption page."
            : "{$adopter} says they need help with {$animal}. Read their update on the Post-adoption page.";
    }

    public function data(): array
    {
        return [
            'adoption_application_id' => $this->item->adoption_application_id,
            'animal_id' => $this->item->animal_id,
        ];
    }
}
