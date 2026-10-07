<?php

namespace App\Notifications;

use App\Models\RescueReport;
use App\Models\Team;

/** To each member of a team, when a rescue report is assigned to their team. */
class RescueAssignedToTeam extends AppNotification
{
    public function __construct(private RescueReport $report, private Team $team) {}

    public function type(): string
    {
        return 'team_rescue';
    }

    public function title(): string
    {
        return 'Rescue assigned to '.$this->team->name;
    }

    public function message(): string
    {
        $leader = $this->team->leader?->user?->full_name;

        return "{$this->team->name} has been assigned a rescue at {$this->report->location} "
            ."(urgency: {$this->report->urgency}).".($leader ? " Coordinate with your team leader, {$leader}." : '');
    }

    public function data(): array
    {
        return [
            'rescue_report_id' => $this->report->id,
            'team_id' => $this->team->id,
        ];
    }
}
