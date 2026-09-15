<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One place to send the app's plain-text transactional emails (verification links, password
 * resets), so the transport choice lives in a single spot.
 *
 * Why this exists: Render's free tier blocks outbound SMTP (ports 25/465/587) since Sept 2025,
 * so Gmail SMTP times out in production. When a Brevo API key is configured we send over Brevo's
 * HTTPS API (port 443, not blocked); otherwise we fall back to Laravel's configured mailer
 * (SMTP locally, or `log`), which keeps local dev working exactly as before.
 *
 * Sending is best-effort: a transport failure is logged, never thrown, so a request that happens
 * to send an email (register, forgot-password) never 500s because mail is down.
 */
class Mailer
{
    /**
     * Send a plain-text email. Returns true if the transport accepted it, false on failure.
     */
    public static function send(string $toEmail, ?string $toName, string $subject, string $text): bool
    {
        $brevoKey = config('services.brevo.key');

        if ($brevoKey) {
            return self::sendViaBrevo($brevoKey, $toEmail, $toName, $subject, $text);
        }

        // No API key configured — use the framework mailer (local dev: SMTP or log). Not used in
        // production, where SMTP is blocked, so no HTTPS fallback is attempted here.
        try {
            Mail::raw($text, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)->subject($subject);
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('Mailer: framework send failed', ['to' => $toEmail, 'exception' => $e]);

            return false;
        }
    }

    /**
     * POST the message to Brevo's transactional email API. The sender is the app's configured
     * "from" address, which must be a verified sender in the Brevo account.
     */
    private static function sendViaBrevo(string $key, string $toEmail, ?string $toName, string $subject, string $text): bool
    {
        $from = config('mail.from');

        try {
            $response = Http::withHeaders([
                'api-key' => $key,
                'accept' => 'application/json',
            ])->timeout(15)->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'name' => $from['name'] ?? config('app.name'),
                    'email' => $from['address'],
                ],
                'to' => [array_filter([
                    'email' => $toEmail,
                    'name' => $toName,
                ])],
                'subject' => $subject,
                'textContent' => $text,
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('Mailer: Brevo API rejected the message', [
                'to' => $toEmail,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Mailer: Brevo API request failed', ['to' => $toEmail, 'exception' => $e]);

            return false;
        }
    }
}
