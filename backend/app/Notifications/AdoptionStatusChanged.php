<?php

namespace App\Notifications;

use App\Models\AdoptionApplication;

class AdoptionStatusChanged extends AppNotification
{
    public function __construct(private AdoptionApplication $application)
    {
    }

    public function type(): string
    {
        return 'adoption_status';
    }

    public function title(): string
    {
        return 'Adoption application update';
    }

    public function message(): string
    {
        $animal = $this->application->animal->name ?? 'the animal';

        if ($this->application->status === 'completed') {
            return "Your adoption of {$animal} is complete — welcome home, {$animal}! We will check in after 1 week, "
                .'1 month, 3 months and 6 months, and you can send us updates and photos any time from My Adopted Pets on your dashboard.';
        }

        return "Your adoption application for {$animal} is now \"{$this->application->status}\".";
    }

    public function data(): array
    {
        return [
            'adoption_application_id' => $this->application->id,
            'animal_id' => $this->application->animal_id,
            'status' => $this->application->status,
        ];
    }
}
