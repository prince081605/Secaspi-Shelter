<?php

use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDue;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Notify admins of health reminders whose due date has arrived, then mark them sent
 * so they aren't re-notified daily. Idempotent and safe to run repeatedly.
 *
 * Note: the admin dashboard (GET /admin/reminders) is the always-on surface and does
 * not depend on this command running. This adds proactive email/in-app pings *if* a
 * scheduler (`php artisan schedule:work` or cron) is configured in the environment.
 */
Artisan::command('reminders:dispatch', function () {
    $due = Reminder::where('status', 'pending')
        ->whereDate('reminder_date', '<=', now())
        ->get();

    if ($due->isEmpty()) {
        $this->info('No reminders due.');
        return;
    }

    $admins = User::where('role', 'admin')->get();

    foreach ($due as $reminder) {
        foreach ($admins as $admin) {
            (new ReminderDue($reminder))->sendTo($admin);
        }
        $reminder->update(['status' => 'sent']);
    }

    $this->info("Dispatched {$due->count()} reminder(s) to {$admins->count()} admin(s).");
})->purpose('Notify admins of due health reminders');

Schedule::command('reminders:dispatch')->dailyAt('08:00');
