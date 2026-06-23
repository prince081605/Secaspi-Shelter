<?php

namespace App\Notifications;

use App\Models\Visitation;
use Illuminate\Support\Carbon;

class VisitationStatusChanged extends AppNotification
{
    public function __construct(private Visitation $visitation)
    {
    }

    public function type(): string
    {
        return 'visitation_status';
    }

    public function title(): string
    {
        return 'Visit request update';
    }

    public function message(): string
    {
        $date = Carbon::parse($this->visitation->requested_date)->format('M j, Y');

        return "Your visit request for {$date} ({$this->visitation->time_slot}) is now \"{$this->visitation->status}\".";
    }

    public function data(): array
    {
        return [
            'visitation_id' => $this->visitation->id,
            'status' => $this->visitation->status,
            'requested_date' => $this->visitation->requested_date,
        ];
    }
}
