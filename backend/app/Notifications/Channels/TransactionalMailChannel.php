<?php

namespace App\Notifications\Channels;

use App\Notifications\AppNotification;
use App\Support\Mailer;

/**
 * Delivers an AppNotification's email through App\Support\Mailer — Brevo's HTTPS API when
 * BREVO_API_KEY is set, otherwise the framework mailer.
 *
 * Laravel's built-in 'mail' channel always speaks SMTP, which Render's free tier blocks: every
 * notification sent there hung its request until the socket timed out (the payment return page
 * sat on "Opening secure checkout…" for 1–2 minutes). Routing through Mailer gives notifications
 * the same working transport as verification and password-reset emails.
 */
class TransactionalMailChannel
{
    public function send(object $notifiable, AppNotification $notification): void
    {
        if (empty($notifiable->email)) {
            return;
        }

        Mailer::send($notifiable->email, $notifiable->full_name ?? null, $notification->title(), $notification->toText($notifiable));
    }
}
