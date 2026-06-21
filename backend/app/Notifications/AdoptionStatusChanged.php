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
