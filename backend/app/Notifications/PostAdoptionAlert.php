<?php

namespace App\Notifications;

use App\Models\AdoptionReturn;
use App\Models\AdoptionUpdate;

/**
 * To staff and admins, when an adopter needs the shelter: an update saying they need help, or a
 * request to return the animal. Everything else waits quietly on the Post-adoption page.
 */
class PostAdoptionAlert extends AppNotification
{
    public function __construct(private AdoptionUpdate|AdoptionReturn $item) {}

    public function type(): string
    {
        return 'post_adoption_alert';
    }

    public function title(): string
    {
        return $this->item instanceof AdoptionReturn ? 'Adoption return requested' : 'An adopter needs help';
    }

    public function message(): string
    {
        $animal = $this->item->animal->name ?? 'an adopted animal';
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
