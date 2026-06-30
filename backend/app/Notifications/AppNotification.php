<?php

namespace App\Notifications;

use App\Models\Notification as NotificationRecord;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Single source of truth for an event's title/message/data, sent over both channels: a row in
 * `app_notifications` for the in-app bell, and an email via Laravel's mail channel (currently the
 * `log` driver per .env — becomes real delivery the moment MAIL_MAILER/credentials are set, no
 * code change needed).
 *
 * Implements ShouldQueue so the email send is pushed to the queue instead of blocking the HTTP
 * response — important once real SMTP is configured and one event fans out to many staff/admins.
 * (With QUEUE_CONNECTION=sync it still runs inline; the in-app bell row is created immediately.)
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

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

    public function sendTo(User $user): void
    {
        $user->notify($this);

        NotificationRecord::create([
            'user_id' => $user->id,
            'type' => $this->type(),
            'title' => $this->title(),
            'message' => $this->message(),
            'data' => $this->data(),
        ]);
    }
}
