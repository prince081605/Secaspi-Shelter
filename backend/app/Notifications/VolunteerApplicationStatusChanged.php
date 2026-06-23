<?php

namespace App\Notifications;

use App\Models\VolunteerApplication;

class VolunteerApplicationStatusChanged extends AppNotification
{
    public function __construct(private VolunteerApplication $application)
    {
    }

    public function type(): string
    {
        return 'volunteer_application_status';
    }

    public function title(): string
    {
        return 'Volunteer application update';
    }

    public function message(): string
    {
        return match ($this->application->status) {
            'approved' => "Great news! Your volunteer application has been approved. Welcome to the team — you can now request tasks from your volunteer dashboard.",
            'rejected' => "Thank you for your interest. Unfortunately your volunteer application was not approved at this time.",
            default => "Your volunteer application status is now \"{$this->application->status}\".",
        };
    }

    public function data(): array
    {
        return [
            'volunteer_application_id' => $this->application->id,
            'status' => $this->application->status,
        ];
    }
}
