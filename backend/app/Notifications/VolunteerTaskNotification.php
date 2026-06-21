<?php

namespace App\Notifications;

use App\Models\VolunteerTask;

class VolunteerTaskNotification extends AppNotification
{
    /**
     * @param 'assigned'|'updated' $action
     */
    public function __construct(private VolunteerTask $task, private string $action)
    {
    }

    public function type(): string
    {
        return 'volunteer_task';
    }

    public function title(): string
    {
        return $this->action === 'assigned' ? 'New volunteer task assigned' : 'Volunteer task update';
    }

    public function message(): string
    {
        return $this->action === 'assigned'
            ? "You've been assigned a new task: \"{$this->task->task_name}\"."
            : "Your task \"{$this->task->task_name}\" is now \"{$this->task->status}\".";
    }

    public function data(): array
    {
        return [
            'volunteer_task_id' => $this->task->id,
            'task_name' => $this->task->task_name,
            'status' => $this->task->status,
        ];
    }
}
