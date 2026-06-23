<?php

namespace App\Notifications;

use App\Models\Reminder;
use Illuminate\Support\Carbon;

class ReminderDue extends AppNotification
{
    public function __construct(private Reminder $reminder)
    {
    }

    public function type(): string
    {
        return 'reminder_due';
    }

    public function title(): string
    {
        return 'Health reminder due';
    }

    public function message(): string
    {
        $date = Carbon::parse($this->reminder->reminder_date)->format('M j, Y');

        return "{$this->reminder->title} (due {$date}).";
    }

    public function data(): array
    {
        return [
            'reminder_id' => $this->reminder->id,
            'animal_id' => $this->reminder->animal_id,
            'reminder_date' => $this->reminder->reminder_date,
        ];
    }
}
