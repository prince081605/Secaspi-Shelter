<?php

namespace App\Notifications;

use App\Models\FosterApplication;

class FosterStatusChanged extends AppNotification
{
    public function __construct(private FosterApplication $application)
    {
    }

    public function type(): string
    {
        return 'foster_status';
    }

    public function title(): string
    {
        return 'Foster application update';
    }

    public function message(): string
    {
        $animal = $this->application->animal->name ?? 'the animal';

        return "Your foster application for {$animal} is now \"{$this->application->status}\".";
    }

    public function data(): array
    {
        return [
            'foster_application_id' => $this->application->id,
            'animal_id' => $this->application->animal_id,
            'status' => $this->application->status,
        ];
    }
}
