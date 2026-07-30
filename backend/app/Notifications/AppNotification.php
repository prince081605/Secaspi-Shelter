<?php

namespace App\Notifications;

use App\Models\Notification as NotificationRecord;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for an event's title/message/data, sent over both channels: a row in
 * `app_notifications` for the in-app bell, and an email via Laravel's mail channel (currently the
 * `log` driver per .env — becomes real delivery the moment MAIL_MAILER/credentials are set, no
 * code change needed).
 *
 * NOTE: deliberately NOT `implements ShouldQueue`. Notifications run synchronously so email
 * delivery never depends on a running queue worker — prod uses `QUEUE_CONNECTION=database`
 * (per .env.example) with no worker, so queued jobs would pile up unsent. Re-enable ShouldQueue
 * only alongside a worker (or `QUEUE_CONNECTION=sync`). See audit §9.
 */
abstract class AppNotification extends Notification
{

    abstract public function type(): string;

    abstract public function title(): string;

    abstract public function message(): string;

    public function data(): array
    {
        return [];
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.$notifiable->full_name.',')
            ->line($this->message())
            ->action('View on SECASPI Shelter', rtrim(config('app.frontend_url'), '/').'/dashboard');
    }

    /**
     * The in-app record is written first and the email is attempted second, inside a
     * try/catch. Both orderings matter: notifications send synchronously (see above), so
     * an SMTP outage throws right here — mailing first meant the bell record was never
     * created and, worse, the exception propagated into whatever business action
     * triggered it (verifying a donation would 500 *after* committing the status, leaving
     * the admin's screen disagreeing with the database).
     *
     * A notification is a side effect of an action, never the thing that decides whether
     * the action succeeded.
     */
    public function sendTo(User $user): void
    {
        NotificationRecord::create([
            'user_id' => $user->id,
            'type' => $this->type(),
            'title' => $this->title(),
            'message' => $this->message(),
            'data' => $this->data(),
        ]);

        try {
            $user->notify($this);
        } catch (\Throwable $e) {
            Log::error('Notification email could not be delivered', [
                'user_id' => $user->id,
                'type' => $this->type(),
                'exception' => $e,
            ]);
        }
    }
}
