<?php

namespace App\Notifications;

use App\Models\AdoptionReturn;
use App\Models\AdoptionUpdate;

/**
 * To the adopter: their Happy Tails story was published, or their return request was decided.
 */
class PostAdoptionNews extends AppNotification
{
    public function __construct(private AdoptionUpdate|AdoptionReturn $item) {}

    public function type(): string
    {
        return $this->item instanceof AdoptionReturn ? 'adoption_return' : 'happy_tails';
    }

    public function title(): string
    {
        if ($this->item instanceof AdoptionUpdate) {
            return 'Your Happy Tails story is live';
        }

        return $this->item->status === 'accepted' ? 'Return request accepted' : 'Return request reviewed';
    }

    public function message(): string
    {
        $animal = $this->item->animal->name ?? 'your pet';

        if ($this->item instanceof AdoptionUpdate) {
            return "Your update about {$animal} is now on the Happy Tails section of our website. Thank you for sharing it!";
        }

        $notes = $this->item->staff_notes ? " Note from the shelter: {$this->item->staff_notes}" : '';

        return $this->item->status === 'accepted'
            ? "We have accepted your request to return {$animal}. The shelter will contact you to arrange bringing {$animal} back.{$notes}"
            : "We have reviewed your request to return {$animal} and can't accept it right now. Please message us so we can talk through what is happening and how we can help.{$notes}";
    }

    public function data(): array
    {
        return [
            'adoption_application_id' => $this->item->adoption_application_id,
            'animal_id' => $this->item->animal_id,
        ];
    }
}
